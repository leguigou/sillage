<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{DB, Security};
final class Development
{
    public static function request(array $c, string $resource): array
    {
        $repo = $c['repository'];
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo)) {
            throw new \InvalidArgumentException('Dépôt invalide.');
        }
        $github = $c['provider'] === 'github';
        $base = $github
            ? 'https://api.github.com/repos/'
            : 'https://api.bitbucket.org/2.0/repositories/';
        $url = $base . $repo . '/' . $resource;
        $ch = curl_init($url);
        $token = Security::decrypt($c['secret_cipher']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Sillage/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_PROTOCOLS_STR => 'https',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new \InvalidArgumentException(
                'Connexion au fournisseur impossible (HTTP ' .
                    $status .
                    '). Vérifiez le dépôt et les permissions du jeton.',
            );
        }
        if (strlen($body) > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('Réponse du fournisseur trop volumineuse.');
        }
        $data = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Réponse du fournisseur invalide.');
        }
        return $data;
    }
    public static function ingest(
        array $connection,
        string $key,
        string $kind,
        string $title,
        string $url,
        string $state,
        string $date,
    ): void {
        if (!preg_match('#^https://(github\.com|bitbucket\.org)/#D', $url)) {
            $url = '';
        }
        $p = DB::one('SELECT project_key FROM projects WHERE id=?', [$connection['project_id']]);
        $ticket = null;
        if (preg_match('/\b' . preg_quote($p['project_key'], '/') . '-(\d+)\b/i', $title, $m)) {
            $ticket = DB::one('SELECT id FROM tickets WHERE project_id=? AND number=?', [
                $connection['project_id'],
                (int) $m[1],
            ]);
        }
        try {
            $time = (new \DateTimeImmutable($date))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            $time = gmdate('Y-m-d H:i:s');
        }
        DB::run(
            'INSERT INTO development_events(connection_id,ticket_id,external_key,kind,title,external_url,state,occurred_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ticket_id=VALUES(ticket_id),title=VALUES(title),state=VALUES(state),occurred_at=VALUES(occurred_at)',
            [
                $connection['id'],
                $ticket['id'] ?? null,
                mb_substr($key, 0, 190),
                $kind,
                mb_substr($title, 0, 500),
                $url,
                $state,
                $time,
            ],
        );
    }
    public static function sync(array $c): int
    {
        $github = $c['provider'] === 'github';
        $data = self::request(
            $c,
            $github ? 'pulls?state=all&per_page=50' : 'pullrequests?state=OPEN&pagelen=50',
        );
        $items = $github ? $data : $data['values'] ?? [];
        $n = 0;
        foreach ($items as $pr) {
            self::ingest(
                $c,
                'pr:' . ($pr['number'] ?? $pr['id']),
                'pull_request',
                ($pr['title'] ?? '') .
                    ' ' .
                    ($pr['head']['ref'] ?? ($pr['source']['branch']['name'] ?? '')),
                $pr['html_url'] ?? ($pr['links']['html']['href'] ?? ''),
                !empty($pr['merged_at']) ? 'merged' : $pr['state'] ?? '',
                $pr['updated_at'] ?? ($pr['updated_on'] ?? 'now'),
            );
            $n++;
        }
        DB::run('UPDATE connections SET last_sync_at=UTC_TIMESTAMP() WHERE id=?', [$c['id']]);
        return $n;
    }
}
