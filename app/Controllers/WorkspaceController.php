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
        if (!$projects && $section !== 'settings') {
            View::render('initial-project', [
                'guest' => true,
                'title' => 'Bienvenue dans Sillage',
                'user' => $user,
            ]);
            return;
        }
        $defaultProject = (int) ($_SESSION['project_id'] ?? ($projects[0]['id'] ?? 0));
        if ($projects && !in_array($defaultProject, array_column($projects, 'id'), true)) {
            $defaultProject = (int) $projects[0]['id'];
        }
        $project = $projects
            ? Project::find((int) ($_GET['project'] ?? $defaultProject))
            : ['id' => 0, 'name' => \App\Core\Company::name(), 'project_key' => ''];
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
        $mcpTokens =
            $section === 'settings'
                ? DB::all(
                    'SELECT id,name,fingerprint,scopes,auth_version,expires_at,last_used_at,created_at FROM mcp_tokens WHERE user_id=? ORDER BY id DESC',
                    [$user['id']],
                )
                : [];
        $mcpAudit =
            $section === 'settings'
                ? DB::all(
                    'SELECT a.tool_name,a.succeeded,a.created_at,k.name token_name FROM mcp_audit a LEFT JOIN mcp_tokens k ON k.id=a.token_id WHERE a.user_id=? ORDER BY a.id DESC LIMIT 20',
                    [$user['id']],
                )
                : [];
        $automationRules =
            $section === 'automation'
                ? array_map(
                    fn($r) => \App\Models\Automation::find((int) $r['id']),
                    DB::all('SELECT id FROM automation_rules WHERE project_id=? ORDER BY id', [
                        $project['id'],
                    ]),
                )
                : [];
        $automationRuns =
            $section === 'automation'
                ? DB::all(
                    'SELECT r.*,t.number FROM automation_runs r JOIN tickets t ON t.id=r.ticket_id WHERE t.project_id=? ORDER BY r.id DESC LIMIT 30',
                    [$project['id']],
                )
                : [];
        $editingRule = null;
        if ($section === 'automation' && !empty($_GET['edit'])) {
            $editingRule = \App\Models\Automation::find((int) $_GET['edit']);
            if ((int) $editingRule['project_id'] !== (int) $project['id']) {
                throw new \InvalidArgumentException('Règle d’un autre projet.');
            }
        }
        $accounts =
            $section === 'settings' && $user['role'] === 'admin'
                ? DB::all(
                    'SELECT id,name,email,role,active,verified_at FROM users ORDER BY name,id',
                )
                : [];
        $teams = DB::all('SELECT * FROM teams ORDER BY name,id');
        $projectTeams=\App\Models\Team::forProject((int)$project['id']);
        $teamProjects=[];
        if ($section==='settings') foreach(DB::all('SELECT tp.team_id,p.id,p.name,p.project_key FROM team_projects tp JOIN projects p ON p.id=tp.project_id ORDER BY p.name,p.id') as $linked) $teamProjects[$linked['team_id']][]=$linked;
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
        foreach (['status', 'type', 'priority', 'assignee', 'team'] as $field) {
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
        $connections = \App\Models\RepositoryConnection::forProject((int)$project['id']);
        $repositoryCatalog = $user['role']==='admin' ? \App\Models\RepositoryConnection::all() : [];
        $events = \App\Models\DevelopmentEvent::withTickets(DB::all(
            'SELECT d.*,c.provider,c.repository FROM development_events d JOIN connections c ON c.id=d.connection_id JOIN project_connections pc ON pc.connection_id=c.id WHERE pc.project_id=? ORDER BY d.occurred_at DESC,d.id DESC LIMIT 100',
            [$project['id']],
        ),(int)$project['id']);
        $title = match ($section) {
            'board' => 'Tableau',
            'list' => 'Liste',
            'all' => 'Tous les tickets',
            'timeline' => 'Chronologie',
            'forms' => 'Formulaires',
            'development' => 'Développement',
            'charts' => 'Rapports',
            'settings' => 'Paramètres',
            'automation' => 'Automatisations',
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
                'mcpTokens',
                'mcpAudit',
                'automationRules',
                'automationRuns',
                'editingRule',
                'accounts',
                'teams',
                'teamMembers',
                'teamProjects',
                'projectTeams',
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
                'repositoryCatalog',
                'events',
                'title',
                'error',
            ),
        );
    }
    public function updateProject(string $id): void
    {
        Auth::admin();
        if (isset($_POST['teams_present'])) $_POST['team_ids']=$_POST['team_ids']??[];
        Project::save($_POST,(int)$id);
        flash('Projet, équipes et dépôt principal enregistrés.');
        redirect('/?project='.(int)$id);
    }
    public function project(): void
    {
        Auth::admin();
        if (isset($_POST['teams_present'])) $_POST['team_ids']=$_POST['team_ids']??[];
        $id = Project::save($_POST);
        flash('Votre nouveau projet est prêt.');
        redirect('/?project=' . $id);
    }
    public function saveView(): void
    {
        $u = Auth::requireUser();
        $pid = (int) ($_POST['project_id'] ?? 0);
        $settings = json_decode(
            (string) ($_POST['settings'] ?? '{}'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($settings)) {
            throw new \InvalidArgumentException('Vue invalide.');
        }
        \App\Models\SavedView::save(
            (int) $u['id'],
            $pid,
            (string) ($_POST['name'] ?? ''),
            $settings,
        );
        flash('Vue enregistrée.');
        redirect('/list?project=' . $pid);
    }
    public function deleteView(string $id): void
    {
        $u = Auth::requireUser();
        \App\Models\SavedView::delete((int) $id, (int) $u['id']);
        flash('Vue supprimée.');
        redirect('/list');
    }
    public function rule(): void
    {
        Auth::admin();
        $pid = (int) ($_POST['project_id'] ?? 0);
        \App\Models\FormattingRule::save($_POST);
        flash('Règle créée.');
        redirect('/list?project=' . $pid);
    }
    public function deleteRule(string $id): void
    {
        Auth::admin();
        \App\Models\FormattingRule::delete((int) $id);
        redirect('/list');
    }
    public function account(): void
    {
        $u = Auth::requireUser();
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!$name || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Nom invalide.');
        }
        \App\Models\UserAvatar::saveProfile((int)$u['id'], $name, \App\Services\ImageUpload::fromUpload($_FILES['avatar'] ?? [], 'La photo'), !empty($_POST['remove_avatar']));
        flash('Profil mis à jour.');
        redirect('/settings');
    }
}
