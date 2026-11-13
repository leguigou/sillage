<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, View};
use App\Models\{Project, Ticket};
use App\Services\{Filter, FilterMemory, ListLayout, Query};
/**
 * The main workspace screens and project-level settings.
 *
 * - show(): every section of the "workspace" view: / (dashboard), /list,
 *   /board, /timeline, /forms, /development, /charts, /settings, /all,
 *   /automation, /releases. Any signed-in user; admin-only data (user
 *   accounts, repository catalog) is only loaded for admins.
 * - POST /projects, /projects/{id}/update, /projects/{id}/archive, GET /projects/{id}/settings
 *   (HTML fragment): project management, admin only.
 * - POST /views, /views/{id}/delete: the user's own saved list views ("rapports").
 * - POST /rules, /rules/{id}/delete: list formatting rules, admin only.
 * - POST /account: the user's own name and avatar.
 */
final class WorkspaceController
{
    /**
     * Renders one workspace section for the current project (?project=, else
     * the last one used in this session, else the first). Loads the filtered
     * tickets plus the extra data each section needs.
     */
    public function show(string $section = 'dashboard'): void
    {
        $user = Auth::requireUser();
        $projects = Project::all();
        // No project yet: show the onboarding screen (settings stays reachable).
        if (!$projects && $section !== 'settings') {
            View::render('initial-project', [
                'guest' => true,
                'title' => 'Bienvenue dans ' . app_name(),
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
        $activeView = null;
        $userId = (int) $user['id'];
        $projectId = (int) $project['id'];
        $isList = $section === 'list';
        $reset = !empty($_GET['filters_reset']);
        $reportId = $_GET['view'] ?? null;
        // On /list without explicit filters, reopen the saved view last used for
        // this project (remembered per user in the session).
        if ($isList && !$reset && !FilterMemory::hasExplicitFilters($_GET)) {
            $reportId = $_SESSION['list_reports'][$userId][$projectId] ?? null;
        }
        $reportId = filter_var($reportId, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        // A saved view is only loaded if it belongs to the current user and project;
        // its stored settings are the base, overridden by the query string.
        if (!$reset && $reportId) {
            $activeView = DB::one('SELECT * FROM saved_views WHERE id=? AND user_id=? AND project_id=?', [
                $reportId, $userId, $projectId,
            ]);
            if ($activeView) {
                $filters = array_replace(json_decode($activeView['settings'], true), $_GET, ['view'=>$reportId]);
            }
        }
        // Invalid filters do not break the page: they are dropped and the error is displayed.
        $error = null;
        try {
            $ticketSection = in_array($section, ['dashboard','list','all','board','timeline','charts'], true);
            // A report is applied the same way in every ticket view: its normalised settings, without
            // touching the remembered filters. The timeline keeps its period (scale, from).
            if ($ticketSection && $activeView) {
                $period = array_intersect_key($filters, ['scale'=>1,'from'=>1]);
                $filters = ListLayout::settings($pid, $filters) + ['view'=>$reportId] + $period;
            } elseif ($ticketSection) {
                $filters = FilterMemory::resolve($userId, $pid, $filters);
            }
            Filter::where($pid, $filters);
            ListLayout::columns($filters);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
            $filters = [];
            $activeView = null;
        }
        if ($isList) {
            $reports = $_SESSION['list_reports'][$userId] ?? [];
            unset($reports[$projectId]);
            if ($activeView) $reports[$projectId] = $reportId;
            $_SESSION['list_reports'][$userId] = array_slice($reports, -20, null, true);
        }
        // Board and timeline load up to 1000 tickets at once; list views paginate by 50.
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = in_array($section, ['board', 'timeline'], true) ? 1000 : 50;
        $count = Query::count($pid, $filters);
        $ticketFilters=$section==='board'?array_replace($filters,['sort'=>'rank','direction'=>'asc','sort2'=>'','sort3'=>'']):$filters;
        $tickets = Query::tickets(
            $pid,
            $ticketFilters,
            $limit,
            $section === 'list' || $all ? ($page - 1) * $limit : 0,
        );
        $members = Project::members();
        $mcpTokens =
            $section === 'settings'
                ? DB::all(
                    'SELECT id,name,fingerprint,scopes,auth_version,expires_at,last_used_at,created_at
                        FROM mcp_tokens
                        WHERE user_id=? AND oauth_grant_id IS NULL
                        ORDER BY id DESC',
                    [$user['id']],
                )
                : [];
        $mcpAudit =
            $section === 'settings'
                ? DB::all(
                    'SELECT a.tool_name,a.succeeded,a.created_at,k.name token_name
                        FROM mcp_audit a
                        LEFT JOIN mcp_tokens k ON k.id=a.token_id
                        WHERE a.user_id=?
                        ORDER BY a.id DESC
                        LIMIT 20',
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
        // ?edit= must reference a rule of the current project.
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
        // Archived projects are only listed to admins, in the settings directory, to reactivate them.
        $archivedProjects = $section === 'settings' && $user['role'] === 'admin' ? Project::archived() : [];
        $teams = DB::all('SELECT * FROM teams ORDER BY name,id');
        $projectTeams=\App\Models\Team::forProject((int)$project['id']);
        $teamProjects=[];
        if ($section==='settings') foreach(DB::all('SELECT tp.team_id,p.id,p.name,p.project_key
            FROM team_projects tp
            JOIN projects p ON p.id=tp.project_id
            ORDER BY p.name,p.id') as $linked) $teamProjects[$linked['team_id']][]=$linked;
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
            'SELECT h.*,u.name,t.number,p.project_key
                FROM activity h
                LEFT JOIN users u ON u.id=h.user_id
                LEFT JOIN tickets t ON t.id=h.ticket_id
                JOIN projects p ON p.id=h.project_id
                WHERE h.project_id=?
                ORDER BY h.id DESC
                LIMIT 16',
            [$project['id']],
        );
        $stats = [];
        foreach (['status', 'type', 'priority', 'assignee', 'team'] as $field) {
            $stats[$field] = Query::aggregate($pid, $filters, $field);
        }
        // Dashboard KPIs over the filtered tickets: last 7 days activity, and
        // open tickets due within 7 days or overdue.
        [$where, $args] = Filter::where($pid, $filters);
        $kpis = DB::one(
            "SELECT SUM(t.completed_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY) completed,SUM(t.created_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY) created,SUM(t.updated_at>=UTC_TIMESTAMP()-INTERVAL 7 DAY) updated,SUM(".\App\Models\Workflow::categorySql()." NOT IN ('done','paused') AND t.due_date BETWEEN UTC_DATE() AND UTC_DATE()+INTERVAL 7 DAY) upcoming,SUM(".\App\Models\Workflow::categorySql()." NOT IN ('done','paused') AND t.due_date<UTC_DATE()) overdue" .
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
        $events = $section==='development'?\App\Models\DevelopmentEvent::withTickets(DB::all(
            'SELECT '.\App\Models\DevelopmentEvent::summaryColumns('d').',c.provider,c.repository
                FROM development_events d
                JOIN connections c ON c.id=d.connection_id
                JOIN project_connections pc ON pc.connection_id=c.id
                WHERE pc.project_id=?
                ORDER BY d.occurred_at DESC,d.id DESC
                LIMIT 100',
            [$project['id']],
        ),(int)$project['id']):[];
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
            'releases' => 'Versions et livraisons',
            default => 'Vue d’ensemble',
        };
        View::render(
            'workspace',
            compact(
                'section',
                'user',
                'projects',
                'archivedProjects',
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
                'activeView',
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
    /** Saves project {id} settings (details, teams, main repository); JSON reply for AJAX callers. */
    public function updateProject(string $id): void
    {
        Auth::admin();
        // "*_present" hidden fields mark that the checkbox / team list was on the
        // form, so an unchecked box or an empty selection is saved as such.
        if (isset($_POST['validation_present'])) $_POST['require_ticket_validation']=!empty($_POST['require_ticket_validation']);
        if (isset($_POST['teams_present'])) $_POST['team_ids']=$_POST['team_ids']??[];
        Project::save($_POST,(int)$id);
        if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) {
            $saved=Project::find((int)$id);
            json_response(['project'=>array_intersect_key($saved,array_flip(['id','name','project_key','description','color','icon']))]);
        }
        flash('Projet, équipes et dépôt principal enregistrés.');
        redirect('/?project='.(int)$id);
    }
    /** Archives (action=archive) or reactivates (action=restore) project {id}; admin only, back to the settings directory. */
    public function archiveProject(string $id): void
    {
        Auth::admin();
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['archive', 'restore'], true)) {
            throw new \InvalidArgumentException('Action d’archivage invalide.');
        }
        $saved = Project::setArchived((int) $id, $action === 'archive');
        if ($action === 'archive' && (int) ($_SESSION['project_id'] ?? 0) === (int) $id) {
            unset($_SESSION['project_id']);
        }
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_response(['project' => ['id' => (int) $saved['id'], 'archived' => $saved['archived_at'] !== null]]);
        }
        flash($action === 'archive'
            ? 'Projet « '.$saved['name'].' » archivé. Ses tickets sont conservés.'
            : 'Projet « '.$saved['name'].' » réactivé.');
        redirect('/settings#project-directory');
    }
    /** Returns the project settings form as an HTML fragment, fetched by public/assets/app.js to edit a project. */
    public function projectSettings(string $id): void
    {
        Auth::admin();
        $project=Project::find((int)$id);
        $teams=DB::all('SELECT id,name FROM teams ORDER BY name,id');
        $repositoryCatalog=\App\Models\RepositoryConnection::all();
        header('Content-Type: text/html; charset=utf-8');
        require_once ROOT.'/app/Views/icons.php';
        require ROOT.'/app/Views/partials/project-settings.php';
    }
    /** Creates a new project, then opens its dashboard. */
    public function project(): void
    {
        Auth::admin();
        if (isset($_POST['teams_present'])) $_POST['team_ids']=$_POST['team_ids']??[];
        $id = Project::save($_POST);
        flash('Votre nouveau projet est prêt.');
        redirect('/?project=' . $id);
    }
    /**
     * Creates, updates or copies (mode=copy) a personal saved list view.
     * "settings" is the JSON filter state; with report_options, sort, grouping
     * and visible columns (only known columns, in the posted order) are added;
     * with assignment_filter, the posted assignee/team lists and their AND/OR mode replace the saved ones.
     * "revision" lets SavedView::save() detect concurrent edits.
     */
    public function saveView(): void
    {
        $u = Auth::requireUser();
        $pid = (int) ($_POST['project_id'] ?? 0);
        $viewId=($_POST['mode']??'')==='copy'?0:(int)($_POST['view_id']??0);
        $name=(string)($_POST['name']??'');
        if (($_POST['mode']??'')==='column_order') {
            $view=DB::one('SELECT name,settings FROM saved_views WHERE id=? AND project_id=? AND user_id=?',[$viewId,$pid,$u['id']]);
            if (!$view) throw new \InvalidArgumentException('Rapport personnel introuvable.');
            $name=$view['name'];
        }
        try {$settings = json_decode(
            (string) ($view['settings'] ?? $_POST['settings'] ?? '{}'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );} catch(\JsonException) {throw new \InvalidArgumentException('Configuration de rapport invalide.');}
        if (!is_array($settings)) {
            throw new \InvalidArgumentException('Vue invalide.');
        }
        if (isset($view)) {
            try {$order=json_decode((string)($_POST['column_order']??''),true,16,JSON_THROW_ON_ERROR);} catch(\JsonException) {throw new \InvalidArgumentException('Ordre des colonnes invalide.');}
            if (!is_array($order)) throw new \InvalidArgumentException('Ordre des colonnes invalide.');
            $order=\App\Services\ListLayout::columns(['columns'=>$order]);
            $chosen=\App\Services\ListLayout::columns($settings);
            $settings['columns']=array_values(array_unique([...array_intersect($order,$chosen),...$chosen]));
            unset($settings['show_progress']);
        } elseif (array_key_exists('status', $_POST)) {
            $settings['status'] = $_POST['status'];
            unset($settings['pause']);
        }
        // The report dialog always posts its assignee/team block, so unchecked boxes clear the saved selection.
        if(!isset($view) && isset($_POST['assignment_filter'])) {
            unset($settings['assignee'],$settings['team'],$settings['assignment_match']);
            foreach(['assignee','team'] as $key) if(isset($_POST[$key])) $settings[$key]=$_POST[$key];
            if(($_POST['assignment_match']??'')==='any') $settings['assignment_match']='any';
            elseif(isset($_POST['assignment_match']) && $_POST['assignment_match']!=='all') throw new \InvalidArgumentException('Combinaison personne / équipe invalide.');
        }
        if(!isset($view) && isset($_POST['report_options'])) {
            foreach(['sort','direction','group','sort2','sort3'] as $key) $settings[$key]=$_POST[$key]??'';
            foreach(['direction2','direction3'] as $key) $settings[$key]=$_POST[$key]??'asc';
            unset($settings['show_progress']);
            $settings['columns']=$_POST['columns']??[];
            $chosen=\App\Services\ListLayout::columns($settings);
            try {$order=json_decode((string)($_POST['column_order']??'[]'),true,16,JSON_THROW_ON_ERROR);} catch(\JsonException) {throw new \InvalidArgumentException('Ordre des colonnes invalide.');}
            $order=\App\Services\ListLayout::columns(['columns'=>$order]);
            $settings['columns']=array_values(array_intersect($order,$chosen));
            if (isset($_POST['show_progress'])) $settings['show_progress'] = $_POST['show_progress'];
            $settings['hierarchy']=empty($_POST['hierarchy'])?'0':'1';
        }
        $id=\App\Models\SavedView::save(
            (int) $u['id'],
            $pid,
            $name,
            $settings,
            $viewId,
            isset($_POST['revision'])?(string)$_POST['revision']:null,
        );
        $saved=DB::one('SELECT * FROM saved_views WHERE id=? AND user_id=?',[$id,$u['id']]);
        if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) json_response(['ok'=>true,'id'=>$id,'name'=>$saved['name'],'revision'=>\App\Models\SavedView::revision($saved),'url'=>'/list?project='.$pid.'&view='.$id]);
        flash('Rapport personnel enregistré.');
        redirect('/list?project=' . $pid.'&view='.$id);
    }
    public function deleteView(string $id): void
    {
        $u = Auth::requireUser();
        \App\Models\SavedView::delete((int) $id, (int) $u['id']);
        if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) json_response(['ok'=>true]);
        flash('Vue supprimée.');
        redirect('/list');
    }
    /** Creates a formatting rule (colour and title for one ticket field value in lists). */
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
    /** Updates the current user's display name and profile picture (upload or removal). */
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
