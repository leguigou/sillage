<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, Security};
use App\Models\Project;
use App\Services\Development;
/**
 * Git repository connections (GitHub / Bitbucket) and the incoming webhook,
 * behind the /development screen.
 *
 * Admin only (connection management, JSON or form posts):
 * - POST /connections/repositories: list repositories visible with a token.
 * - POST /connections: connect a repository to a project (or attach an existing
 *   shared connection for the same repository).
 * - POST /connections/{id}/update (replace token), /test-access (diagnostics),
 *   /webhook (show webhook URL and secret), /sync (manual paginated import),
 *   /delete (detach from one project).
 * Editors: GET/POST /development/{project}/branches/{event}/tickets to search
 * tickets and link an observed branch to one.
 * Public: POST /webhooks/{id}, registered without CSRF and authenticated by an
 * HMAC signature instead (see webhook()).
 * Access tokens and webhook secrets are stored encrypted (Security::encrypt).
 * Provider calls are in App\Services\Development.
 */
final class DevelopmentController
{
    /** JSON list of tickets that the branch observed in event {event} can be linked to (search ?q=). */
    public function branchCandidates(string $project, string $event): void
    {
        $user = Auth::editor();
        \App\Core\Security::limit('branch-candidates:'.$user['id'], 120, 60);
        $query = $_GET['q'] ?? '';
        if (!is_string($query) || mb_strlen($query) > 120) {
            throw new \InvalidArgumentException('Recherche de tickets invalide.');
        }
        header('Cache-Control: no-store');
        json_response(['tickets'=>\App\Models\TicketBranch::candidates((int)$project, (int)$event, trim($query))]);
    }

    public function linkBranch(string $project, string $event): void
    {
        $user = Auth::editor();
        \App\Core\Security::limit('link-branch:'.$user['id'], 30, 60);
        $ticket = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$ticket) throw new \InvalidArgumentException('Sélectionnez un ticket.');
        $linked = \App\Models\TicketBranch::linkObserved((int)$project, (int)$event, $ticket, (int)$user['id']);
        json_response(['ticket'=>$linked + ['url'=>ticket_url($ticket).'#development']]);
    }

    /**
     * Runs access diagnostics for connection {id} (which must be linked to the
     * posted project). If a token is posted, it is tested as a draft without
     * being saved; otherwise the stored token is used.
     */
    public function testAccess(string $id): void
    {
        $user=Auth::admin();Security::limit('git-access-test:'.$user['id'],10,60);
        $project=filter_var($_POST['project_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $connection=$project?DB::one('SELECT c.*
            FROM connections c
            JOIN project_connections pc ON pc.connection_id=c.id
            WHERE c.id=? AND pc.project_id=?',[(int)$id,$project]):null;
        if(!$connection) throw new \InvalidArgumentException('Ce dépôt n’est pas lié au projet.');
        $token=trim((string)($_POST['token']??''));
        if($token!=='') $connection['secret_cipher']=Security::encrypt(Development::accessToken($token));
        if(function_exists('set_time_limit')) @set_time_limit(40);
        header('Cache-Control: no-store');
        json_response(['checks'=>\App\Services\GitAccessTest::run($connection),'credential'=>$token===''?'saved':'draft','request_id'=>\App\Services\TechnicalLog::requestId()]);
    }
    /** Returns the webhook URL and the decrypted webhook secret of connection {id}, to configure the provider. */
    public function webhookSetup(string $id): void
    {
        $user=Auth::admin();Security::limit('webhook-setup:'.$user['id'],30,60);
        $connection=DB::one('SELECT id,webhook_cipher FROM connections WHERE id=?',[(int)$id]);
        if (!$connection) throw new \InvalidArgumentException('Connexion Git introuvable.');
        header('Cache-Control: no-store');
        json_response(['url'=>url('/webhooks/'.$connection['id']),'secret'=>Security::decrypt($connection['webhook_cipher'])]);
    }
    /**
     * Replaces the access token of connection {id} after verifying it against
     * the provider. An empty token keeps the current one. The UPDATE only
     * succeeds if the stored token did not change meanwhile (concurrent edit check).
     */
    public function update(string $id): void
    {
        Auth::admin();
        $connection = DB::one('SELECT * FROM connections WHERE id=?',[(int)$id]);
        if (!$connection) throw new \InvalidArgumentException('Connexion introuvable.');
        $token = trim((string)($_POST['token']??''));
        if ($token !== '') {
            $replacement = Security::encrypt(Development::accessToken($token));
            Development::verifyConnection(array_replace($connection,['secret_cipher'=>$replacement]));
            if (DB::run('UPDATE connections
                SET secret_cipher=?
                WHERE id=? AND BINARY secret_cipher=?',[$replacement,(int)$id,$connection['secret_cipher']])->rowCount() !== 1) {
                throw new \InvalidArgumentException('Cette connexion a changé. Rechargez la page avant de réessayer.');
            }
        }
        if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) json_response(['ok'=>true,'preserved'=>$token==='']);
        flash($token===''?'Le jeton enregistré a été conservé.':'Le nouveau jeton a été vérifié et enregistré.');
        redirect('/development?project=' . \App\Models\RepositoryConnection::contextProject((int)$id,(int)($_POST['project_id']??0)));
    }

    /** Lists repositories reachable with the posted token (paginated), flagging those already linked to the project. */
    public function repositories(): void
    {
        Auth::admin();
        $pid = (int) ($_POST['project_id'] ?? 0);
        Project::find($pid);
        $provider = (string) ($_POST['provider'] ?? '');
        $page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT);
        if ($page === false) throw new \InvalidArgumentException('Page de dépôts invalide.');
        $result = Development::discover($provider,(string)($_POST['token']??''),$page,trim((string)($_POST['workspace']??'')));
        $connected = array_map('strtolower', array_column(DB::all('SELECT c.repository
            FROM connections c
            JOIN project_connections pc ON pc.connection_id=c.id
            WHERE pc.project_id=? AND c.provider=?',[$pid,$provider]),'repository'));
        foreach ($result['repositories'] as &$repository) $repository['connected'] = in_array(strtolower($repository['repository']),$connected,true);
        json_response($result);
    }

    public function connect(): void
    {
        Auth::admin();
        $pid = (int) $_POST['project_id'];
        Project::find($pid);
        $provider = (string) ($_POST['provider'] ?? '');
        $repo = Development::repository($provider, (string) ($_POST['repository'] ?? ''));
        $token = Development::accessToken((string) ($_POST['token'] ?? ''));
        // One connection per repository is shared between projects: if it already
        // exists, link it to this project and keep its existing token.
        $shared = DB::one('SELECT id FROM connections WHERE provider=? AND repository=? ORDER BY id LIMIT 1',[$provider,$repo]);
        if ($shared) {
            \App\Models\RepositoryConnection::attach($pid,(int)$shared['id']);
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) json_response(['ok'=>true,'existing'=>true,'repository'=>$repo,'shared'=>true]);
            flash('La connexion Git existante est liée au projet ; son jeton partagé est conservé.');
            redirect('/development?project='.$pid);
        }
        $cipher = Security::encrypt($token);
        $hook = bin2hex(random_bytes(32));
        $c = ['provider' => $provider, 'repository' => $repo, 'secret_cipher' => $cipher];
        Development::verifyConnection($c);
        $id = DB::insert(
            'INSERT INTO connections(project_id,provider,repository,secret_cipher,webhook_cipher) VALUES(?,?,?,?,?)',
            [$pid, $provider, $repo, $cipher, Security::encrypt($hook)],
        );
        \App\Models\RepositoryConnection::attach($pid,$id);
        // The plain webhook secret is put in the session so the next page can display it.
        $_SESSION['webhook_setups'][] = ['repository'=>$repo,'url' => url('/webhooks/' . $id), 'secret' => $hook];
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) json_response(['ok'=>true,'existing'=>false,'repository'=>$repo,'id'=>$id]);
        flash('Dépôt connecté. Configurez le webhook avec le secret affiché une seule fois.');
        redirect('/development?project=' . $pid);
    }
    /** Imports one page (max 50) of branches, commits or pull requests from the provider. */
    public function sync(string $id): void
    {
        Auth::admin();
        $c = DB::one('SELECT * FROM connections WHERE id=?', [(int) $id]);
        if (!$c) {
            throw new \InvalidArgumentException('Connexion introuvable.');
        }
        $kind=(string)($_POST['kind']??'pull_request');
        $page=max(1,(int)($_POST['page']??1));
        $n = Development::sync($c,$kind,$page);
        flash($n . ' éléments synchronisés · '.\App\Services\DevelopmentPayload::KINDS[$kind].' · page '.$page.' (50 maximum par page).');
        redirect('/development?project=' . \App\Models\RepositoryConnection::contextProject((int)$id,(int)($_POST['project_id']??0)));
    }
    /** Detaches connection {id} from one project (the posted one, or a project it is linked to). */
    public function disconnect(string $id): void
    {
        Auth::admin();
        $project=(int)($_POST['project_id']??0);
        if (!$project) $project=\App\Models\RepositoryConnection::contextProject((int)$id);
        \App\Models\RepositoryConnection::detach($project,(int)$id);
        flash(
            'Dépôt détaché de ce projet. Les connexions partagées avec d’autres projets sont conservées.',
        );
        redirect('/development?project='.$project);
    }
    /**
     * Receives push / pull request / CI events from the Git provider.
     * No user session: the request is trusted only if its HMAC-SHA256 signature
     * (X-Hub-Signature-256 or X-Hub-Signature header) matches the body signed
     * with this connection's webhook secret, and the payload's repository
     * matches the connection. Body limited to 1 MB, 120 calls/min per IP.
     */
    public function webhook(string $id): void
    {
        Security::limit('webhook', 120, 60);
        $c = DB::one('SELECT * FROM connections WHERE id=?', [(int) $id]);
        if (!$c) {
            json_response(['error' => 'Not found'], 404);
        }
        \App\Services\TechnicalLog::incoming(['provider'=>$c['provider'],'connection_id'=>(int)$c['id'],'operation'=>'webhook']);
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) {
            json_response(['error' => 'Payload too large'], 413);
        }
        $body = file_get_contents('php://input', false, null, 0, 1048577);
        if (strlen($body) > 1048576) {
            json_response(['error' => 'Payload too large'], 413);
        }
        $signature =
            $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ($_SERVER['HTTP_X_HUB_SIGNATURE'] ?? '');
        $expected = 'sha256=' . hash_hmac('sha256', $body, Security::decrypt($c['webhook_cipher']));
        // Constant-time comparison to avoid leaking the expected signature through timing.
        if (!hash_equals($expected, $signature)) {
            json_response(['error' => 'Signature invalid'], 401);
        }
        $data = json_decode($body, true, 128);
        if (!is_array($data)) {
            json_response(['error' => 'Invalid JSON'], 400);
        }
        $repo = $data['repository']['full_name'] ?? '';
        if (strcasecmp($repo, $c['repository']) !== 0) {
            json_response(['error' => 'Repository mismatch'], 403);
        }
        try {
            // Event name: X-GitHub-Event (GitHub) or X-Event-Key (Bitbucket). Only
            // known names are written to the technical log.
            $event=(string)($_SERVER['HTTP_X_GITHUB_EVENT']??$_SERVER['HTTP_X_EVENT_KEY']??'');
            \App\Services\TechnicalLog::incoming(['operation'=>in_array($event,['push','create','delete','pull_request','repo:push','pullrequest:created','pullrequest:updated','pullrequest:fulfilled','pullrequest:rejected','workflow_run','check_run','check_suite','deployment_status','status','repo:commit_status_created','repo:commit_status_updated'],true)?$event:'unknown_event']);
            $events=\App\Services\DevelopmentPayload::webhook($c['provider'],$event,$data,$c['repository']);
            if (count($events)>250) throw new \InvalidArgumentException('Trop d’éléments dans cette livraison ; utilisez la synchronisation paginée.');
            DB::transaction(function() use($c,$events) {
                foreach($events as $item) \App\Models\DevelopmentEvent::save($c,$item,true);
                \App\Services\GitSync::webhook($c,$events);
            });
        } catch (\InvalidArgumentException $error) {
            json_response(['error'=>$error->getMessage()],400);
        }
        json_response(['ok' => true]);
    }
}
