<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, Security};
use App\Models\Project;
use App\Services\Development;
final class DevelopmentController
{
    public function update(string $id): void
    {
        Auth::admin();
        $connection = DB::one('SELECT * FROM connections WHERE id=?',[(int)$id]);
        if (!$connection) throw new \InvalidArgumentException('Connexion introuvable.');
        $token = trim((string)($_POST['token']??''));
        if ($token !== '') {
            $replacement = Security::encrypt(Development::accessToken($token));
            Development::verifyConnection(array_replace($connection,['secret_cipher'=>$replacement]));
            if (DB::run('UPDATE connections SET secret_cipher=? WHERE id=? AND BINARY secret_cipher=?',[$replacement,(int)$id,$connection['secret_cipher']])->rowCount() !== 1) {
                throw new \InvalidArgumentException('Cette connexion a changé. Rechargez la page avant de réessayer.');
            }
        }
        if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) json_response(['ok'=>true,'preserved'=>$token==='']);
        flash($token===''?'Le jeton enregistré a été conservé.':'Le nouveau jeton a été vérifié et enregistré.');
        redirect('/development?project='.(int)$connection['project_id']);
    }

    public function repositories(): void
    {
        Auth::admin();
        $pid = (int) ($_POST['project_id'] ?? 0);
        Project::find($pid);
        $provider = (string) ($_POST['provider'] ?? '');
        $page = filter_var($_POST['page'] ?? 1, FILTER_VALIDATE_INT);
        if ($page === false) throw new \InvalidArgumentException('Page de dépôts invalide.');
        $result = Development::discover($provider,(string)($_POST['token']??''),$page,trim((string)($_POST['workspace']??'')));
        $connected = array_map('strtolower', array_column(DB::all('SELECT repository FROM connections WHERE project_id=? AND provider=?',[$pid,$provider]),'repository'));
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
        if (
            DB::one(
                'SELECT id FROM connections WHERE project_id=? AND provider=? AND repository=?',
                [$pid, $provider, $repo],
            )
        ) {
            if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) json_response(['ok'=>true,'existing'=>true,'repository'=>$repo]);
            throw new \InvalidArgumentException('Ce dépôt est déjà connecté.');
        }
        $cipher = Security::encrypt($token);
        $hook = bin2hex(random_bytes(32));
        $c = ['provider' => $provider, 'repository' => $repo, 'secret_cipher' => $cipher];
        Development::verifyConnection($c);
        $id = DB::insert(
            'INSERT INTO connections(project_id,provider,repository,secret_cipher,webhook_cipher) VALUES(?,?,?,?,?)',
            [$pid, $provider, $repo, $cipher, Security::encrypt($hook)],
        );
        $_SESSION['webhook_setups'][] = ['repository'=>$repo,'url' => url('/webhooks/' . $id), 'secret' => $hook];
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) json_response(['ok'=>true,'existing'=>false,'repository'=>$repo,'id'=>$id]);
        flash('Dépôt connecté. Configurez le webhook avec le secret affiché une seule fois.');
        redirect('/development?project=' . $pid);
    }
    public function sync(string $id): void
    {
        Auth::admin();
        $c = DB::one('SELECT * FROM connections WHERE id=?', [(int) $id]);
        if (!$c) {
            throw new \InvalidArgumentException('Connexion introuvable.');
        }
        $n = Development::sync($c);
        flash($n . ' pull requests examinées (première page de 50 maximum).');
        redirect('/development?project=' . $c['project_id']);
    }
    public function disconnect(string $id): void
    {
        Auth::admin();
        DB::run('DELETE FROM connections WHERE id=?', [(int) $id]);
        flash(
            'Connexion supprimée. Révoquez également le jeton chez le fournisseur si nécessaire.',
        );
        redirect('/development');
    }
    public function webhook(string $id): void
    {
        Security::limit('webhook', 120, 60);
        $c = DB::one('SELECT * FROM connections WHERE id=?', [(int) $id]);
        if (!$c) {
            json_response(['error' => 'Not found'], 404);
        }
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
        DB::transaction(function () use ($c, $data) {
            $pr = $data['pull_request'] ?? ($data['pullrequest'] ?? null);
            if ($pr) {
                Development::ingest(
                    $c,
                    'pr:' . ($pr['number'] ?? $pr['id']),
                    'pull_request',
                    ($pr['title'] ?? '') .
                        ' ' .
                        ($pr['head']['ref'] ?? ($pr['source']['branch']['name'] ?? '')),
                    $pr['html_url'] ?? ($pr['links']['html']['href'] ?? ''),
                    !empty($pr['merged']) ? 'merged' : $pr['state'] ?? '',
                    $pr['updated_at'] ?? ($pr['updated_on'] ?? 'now'),
                );
            }
            foreach (array_slice($data['commits'] ?? [], 0, 100) as $commit) {
                Development::ingest(
                    $c,
                    'commit:' . ($commit['id'] ?? ''),
                    'commit',
                    ($commit['message'] ?? '') . ' ' . ($data['ref'] ?? ''),
                    $commit['url'] ?? '',
                    'committed',
                    $commit['timestamp'] ?? 'now',
                );
            }
            foreach (array_slice($data['push']['changes'] ?? [], 0, 50) as $change) {
                foreach (array_slice($change['commits'] ?? [], 0, 100) as $commit) {
                    Development::ingest(
                        $c,
                        'commit:' . ($commit['hash'] ?? ''),
                        'commit',
                        $commit['message'] ?? '',
                        $commit['links']['html']['href'] ?? '',
                        'committed',
                        $commit['date'] ?? 'now',
                    );
                };
            }
        });
        json_response(['ok' => true]);
    }
}
