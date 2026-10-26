<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, Security};
use App\Models\Project;
use App\Services\Development;
final class DevelopmentController
{
    public function connect(): void
    {
        Auth::admin();
        $pid = (int) $_POST['project_id'];
        Project::find($pid);
        $provider = (string) ($_POST['provider'] ?? '');
        $repo = trim((string) ($_POST['repository'] ?? ''));
        $token = trim((string) ($_POST['token'] ?? ''));
        if (
            !in_array($provider, ['github', 'bitbucket'], true) ||
            !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo) ||
            strlen($token) < 10 ||
            strlen($token) > 4000
        ) {
            throw new \InvalidArgumentException(
                'Fournisseur, dépôt (organisation/nom) et jeton requis.',
            );
        }
        $cipher = Security::encrypt($token);
        $hook = bin2hex(random_bytes(32));
        $c = ['provider' => $provider, 'repository' => $repo, 'secret_cipher' => $cipher];
        Development::request($c, '');
        if (
            DB::one(
                'SELECT id FROM connections WHERE project_id=? AND provider=? AND repository=?',
                [$pid, $provider, $repo],
            )
        ) {
            throw new \InvalidArgumentException('Ce dépôt est déjà connecté.');
        }
        $id = DB::insert(
            'INSERT INTO connections(project_id,provider,repository,secret_cipher,webhook_cipher) VALUES(?,?,?,?,?)',
            [$pid, $provider, $repo, $cipher, Security::encrypt($hook)],
        );
        $_SESSION['webhook_setup'] = ['url' => url('/webhooks/' . $id), 'secret' => $hook];
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
