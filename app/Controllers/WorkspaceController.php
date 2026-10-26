<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, View};
use App\Models\{Project, Ticket};
use App\Services\{Filter, Query};
final class WorkspaceController
{
    public function show(string $section = 'dashboard'): void
    {
        $user = Auth::requireUser();
        $projects = Project::all();
        if (!$projects) {
            View::render('initial-project', [
                'guest' => true,
                'title' => 'Bienvenue dans Sillage',
                'user' => $user,
            ]);
            return;
        }
        $project = Project::find(
            (int) ($_GET['project'] ?? ($_SESSION['project_id'] ?? $projects[0]['id'])),
        );
        $_SESSION['project_id'] = $project['id'];
        $all = $section === 'all';
        $pid = $all ? 0 : (int) $project['id'];
        $filters = $_GET;
        if (!empty($_GET['view'])) {
            $view = DB::one('SELECT * FROM saved_views WHERE id=? AND user_id=? AND project_id=?', [
                (int) $_GET['view'],
                $user['id'],
                $project['id'],
            ]);
            if ($view) {
                $filters = array_replace(
                    json_decode($view['settings'], true),
                    array_diff_key($_GET, ['view' => 1]),
                );
            }
        }
        $error = null;
        try {
            Filter::where($pid, $filters);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
            $filters = [];
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = in_array($section, ['board', 'timeline'], true) ? 1000 : 50;
        $count = Query::count($pid, $filters);
        $tickets = Query::tickets(
            $pid,
            $filters,
            $limit,
            $section === 'list' || $all ? ($page - 1) * $limit : 0,
        );
        $members = Project::members();
        $accounts =
            $section === 'settings' && $user['role'] === 'admin'
                ? DB::all(
                    'SELECT id,name,email,role,active,verified_at FROM users ORDER BY name,id',
                )
                : [];
        $teams = $section === 'settings' ? DB::all('SELECT * FROM teams ORDER BY name,id') : [];
        $teamMembers = [];
        if ($section === 'settings') {
            foreach (
                DB::all(
                    'SELECT m.team_id,u.id,u.name,u.email,u.active,u.verified_at FROM team_members m JOIN users u ON u.id=m.user_id ORDER BY u.name,u.id',
                )
                as $member
            ) {
                $teamMembers[$member['team_id']][] = $member;
            }
        }
        $activity = DB::all(
            'SELECT h.*,u.name,t.number,p.project_key FROM activity h LEFT JOIN users u ON u.id=h.user_id LEFT JOIN tickets t ON t.id=h.ticket_id JOIN projects p ON p.id=h.project_id WHERE h.project_id=? ORDER BY h.id DESC LIMIT 16',
            [$project['id']],
        );
        $stats = [];
        foreach (['status', 'type', 'priority', 'assignee'] as $field) {
            $stats[$field] = Query::aggregate($pid, $filters, $field);
        }
        [$where, $args] = Filter::where($pid, $filters);
        $kpis = DB::one(
            "SELECT SUM(t.completed_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY) completed,SUM(t.created_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY) created,SUM(t.updated_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY) updated,SUM(t.status<>'done' AND t.due_date BETWEEN UTC_DATE() AND UTC_DATE()+INTERVAL 7 DAY) upcoming,SUM(t.status<>'done' AND t.due_date<UTC_DATE()) overdue" .
                Query::JOIN .
                "WHERE $where",
            $args,
        );
        $views = DB::all('SELECT * FROM saved_views WHERE user_id=? AND project_id=? ORDER BY id', [
            $user['id'],
            $project['id'],
        ]);
        $rules = DB::all('SELECT * FROM formatting_rules WHERE project_id=?', [$project['id']]);
        $forms = DB::all(
            'SELECT f.*,u.name reporter_name FROM public_forms f JOIN users u ON u.id=f.reporter_id WHERE project_id=? ORDER BY f.id DESC',
            [$project['id']],
        );
        $connections = DB::all(
            'SELECT id,provider,repository,last_sync_at FROM connections WHERE project_id=? ORDER BY id',
            [$project['id']],
        );
        $events = DB::all(
            'SELECT d.*,c.provider,c.repository,t.number,p.project_key FROM development_events d JOIN connections c ON c.id=d.connection_id JOIN projects p ON p.id=c.project_id LEFT JOIN tickets t ON t.id=d.ticket_id WHERE c.project_id=? ORDER BY d.occurred_at DESC LIMIT 100',
            [$project['id']],
        );
        $title = match ($section) {
            'board' => 'Tableau',
            'list' => 'Liste',
            'all' => 'Tous les tickets',
            'timeline' => 'Chronologie',
            'forms' => 'Formulaires',
            'development' => 'Développement',
            'charts' => 'Rapports',
            'settings' => 'Paramètres',
            default => 'Vue d’ensemble',
        };
        View::render(
            'workspace',
            compact(
                'section',
                'user',
                'projects',
                'project',
                'tickets',
                'members',
                'accounts',
                'teams',
                'teamMembers',
                'stats',
                'activity',
                'kpis',
                'count',
                'limit',
                'page',
                'filters',
                'views',
                'rules',
                'forms',
                'connections',
                'events',
                'title',
                'error',
            ),
        );
    }
    public function project(): void
    {
        Auth::admin();
        $name = trim((string) ($_POST['name'] ?? ''));
        $key = strtoupper(trim((string) ($_POST['key'] ?? '')));
        if (
            $name === '' ||
            mb_strlen($name) > 120 ||
            !preg_match('/^[A-Z][A-Z0-9]{1,9}$/D', $key)
        ) {
            throw new \InvalidArgumentException(
                'Nom requis et clé de 2 à 10 lettres/chiffres, commençant par une lettre.',
            );
        }
        if (DB::one('SELECT id FROM projects WHERE project_key=?', [$key])) {
            throw new \InvalidArgumentException('Cette clé de projet existe déjà.');
        }
        $id = DB::insert('INSERT INTO projects(name,project_key,description) VALUES(?,?,?)', [
            $name,
            $key,
            mb_substr((string) ($_POST['description'] ?? ''), 0, 2000),
        ]);
        flash('Votre nouveau projet est prêt.');
        redirect('/?project=' . $id);
    }
    public function saveView(): void
    {
        $u = Auth::requireUser();
        $pid = (int) ($_POST['project_id'] ?? 0);
        Project::find($pid);
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!$name || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException(
                'Donnez un nom à votre vue (120 caractères maximum).',
            );
        }
        $settings = json_decode(
            (string) ($_POST['settings'] ?? '{}'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($settings)) {
            throw new \InvalidArgumentException('Vue invalide.');
        }
        Filter::where($pid, $settings);
        DB::run('INSERT INTO saved_views(user_id,project_id,name,settings) VALUES(?,?,?,?)', [
            $u['id'],
            $pid,
            $name,
            json_encode($settings, JSON_THROW_ON_ERROR),
        ]);
        flash('Vue enregistrée.');
        redirect('/list?project=' . $pid);
    }
    public function deleteView(string $id): void
    {
        $u = Auth::requireUser();
        DB::run('DELETE FROM saved_views WHERE id=? AND user_id=?', [(int) $id, $u['id']]);
        flash('Vue supprimée.');
        redirect('/list');
    }
    public function rule(): void
    {
        Auth::admin();
        $pid = (int) $_POST['project_id'];
        Project::find($pid);
        $field = (string) $_POST['field'];
        if (
            !in_array($field, ['status', 'priority', 'type', 'flagged'], true) ||
            !preg_match('/^#[0-9a-f]{6}$/iD', (string) $_POST['color'])
        ) {
            throw new \InvalidArgumentException('Règle invalide.');
        }
        DB::run(
            'INSERT INTO formatting_rules(project_id,field,match_value,color,title) VALUES(?,?,?,?,?)',
            [
                $pid,
                $field,
                mb_substr((string) $_POST['value'], 0, 120),
                $_POST['color'],
                mb_substr((string) $_POST['title'], 0, 120),
            ],
        );
        flash('Règle créée.');
        redirect('/list?project=' . $pid);
    }
    public function deleteRule(string $id): void
    {
        Auth::admin();
        DB::run('DELETE FROM formatting_rules WHERE id=?', [(int) $id]);
        redirect('/list');
    }
    public function account(): void
    {
        $u = Auth::requireUser();
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!$name || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Nom invalide.');
        }
        DB::run('UPDATE users SET name=? WHERE id=?', [$name, $u['id']]);
        flash('Profil mis à jour.');
        redirect('/settings');
    }
}
