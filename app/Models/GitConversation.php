<?php
declare(strict_types=1);
namespace App\Models;

use App\Core\DB;
use App\Services\{Conversation, GitPullRequest, Notifications};

final class GitConversation
{
    private const LIMITS = ['url'=>1000, 'preconditions'=>1000, 'steps'=>6000, 'expected'=>2000];

    public static function canTest(array $event): bool
    {
        return $event['kind'] === 'pull_request'
            && $event['state'] === 'merged'
            && strcasecmp(trim((string)($event['metadata']['target_branch'] ?? '')), 'staging') === 0;
    }

    private static function prLink(array $connection, array $event): string
    {
        $number = substr($event['external_key'], 3);
        $github = $connection['provider'] === 'github';
        $base = $github ? 'https://github.com/' : 'https://bitbucket.org/';
        $path = $github ? '/pull/' : '/pull-requests/';
        $url = $event['external_url'] ?: $base.$connection['repository'].$path.$number;
        return '<a href="'.e($url).'">PR #'.e($number).'</a>';
    }

    /** Called for accepted live PR observations while the affected projects are locked. */
    public static function observe(array $connection, array $event, array $tickets): void
    {
        $opened = $event['trigger'] === 'development.pull_request_opened';
        if (!$opened && !self::canTest($event)) return;

        $kind = $opened ? 'pr_opened' : 'staging_merged';
        $heading = $opened ? 'Pull request ouverte' : 'Fusion sur staging';
        $source = $event['metadata']['branch'] ?? 'Branche source non fournie';
        $target = $event['metadata']['target_branch'] ?? 'Cible non fournie';
        $body = '<p><strong>'.$heading.'</strong></p>'
            .'<p>'.self::prLink($connection, $event).' · '.e($event['title']).'</p>'
            .'<p>'.e($source).' → '.e($target).'</p>';
        if (!$opened) {
            $body .= '<p>Renseignez une procédure rapide pour les personnes chargées de tester. '
                .'Vérifiez que la version est disponible sur le serveur de recette.</p>';
        }
        foreach ($tickets as $id) {
            $ticket = Ticket::find((int)$id);
            if ($ticket['archived'] || DB::one(
                'SELECT id FROM ticket_git_messages WHERE ticket_id=? AND connection_id=? AND pr_key=? AND message_kind=?',
                [$id, $connection['id'], $event['external_key'], $kind],
            )) continue;

            $comment = DB::insert(
                "INSERT INTO comments(ticket_id,user_id,body,body_format) VALUES(?,NULL,?,'html')",
                [$id, Conversation::clean($body, (int)$id)],
            );
            DB::run(
                'INSERT INTO ticket_git_messages(ticket_id,connection_id,event_id,pr_key,message_kind,comment_id) VALUES(?,?,?,?,?,?)',
                [$id, $connection['id'], $event['id'], $event['external_key'], $kind, $comment],
            );
            Ticket::activity((int)$ticket['project_id'], (int)$id, null, $opened
                ? 'une pull request a été ouverte'
                : 'une pull request a été fusionnée sur staging');
            Notifications::enqueue($ticket, ['commented'], null, [], null, [], $comment);
        }
    }

    public static function comments(int $ticket, ?int $comment = null): array
    {
        $sql = "SELECT c.*,COALESCE(u.name,'Sillage') name,g.message_kind,g.event_id git_event_id
            FROM comments c LEFT JOIN users u ON u.id=c.user_id
            LEFT JOIN ticket_git_messages g ON g.comment_id=c.id WHERE c.ticket_id=?";
        return $comment === null
            ? DB::all($sql.' ORDER BY c.id', [$ticket])
            : DB::all($sql.' AND c.id=?', [$ticket, $comment]);
    }

    public static function context(int $ticket, int $event): array
    {
        if (Ticket::find($ticket)['archived']) {
            throw new \InvalidArgumentException('Restaurez le ticket avant de publier une procédure.');
        }
        $pr = GitPullRequest::eventForTicket($ticket, $event);
        if (!self::canTest($pr)) {
            throw new \InvalidArgumentException('Cette procédure nécessite une PR fusionnée vers staging.');
        }
        return $pr;
    }

    public static function publish(int $ticket, int $event, array $input, int $actor): int
    {
        $values = [];
        foreach (self::LIMITS as $key => $max) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > $max) {
                throw new \InvalidArgumentException('La procédure dépasse les limites indiquées.');
            }
            $values[$key] = trim($value);
        }
        if ($values['steps'] === '' || $values['expected'] === '') {
            throw new \InvalidArgumentException('Indiquez les étapes de test et le résultat attendu.');
        }
        $url = $values['url'];
        $parts = parse_url($url);
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)
            || isset($parts['user']) || isset($parts['pass']))) {
            throw new \InvalidArgumentException('Indiquez une URL HTTP(S) du serveur, sans identifiants.');
        }
        $nonce = $input['submission_key'] ?? '';
        if (!is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new \InvalidArgumentException('Rechargez le formulaire de procédure.');
        }
        $project = (int)Ticket::find($ticket)['project_id'];
        return DB::transaction(function () use ($project, $ticket, $event, $actor, $values, $url, $nonce) {
            DB::one('SELECT id FROM projects WHERE id=? FOR UPDATE', [$project]);
            $pr = self::context($ticket, $event);
            $hash = hash('sha256', json_encode([$event, $actor, $values], JSON_THROW_ON_ERROR));
            $old = DB::one(
                'SELECT comment_id,request_hash FROM ticket_test_procedures WHERE ticket_id=? AND submission_key=?',
                [$ticket, $nonce],
            );
            if ($old) {
                if (!hash_equals($old['request_hash'], $hash)) {
                    throw new \InvalidArgumentException('Ce formulaire a déjà été publié avec un autre contenu. Rouvrez-le.');
                }
                return (int)$old['comment_id'];
            }

            $connection = TicketBranch::connection($ticket, (int)$pr['connection_id']);
            $body = '<p><strong>Procédure de test · staging</strong></p><p>'.self::prLink($connection, $pr).'</p>';
            if ($url !== '') $body .= '<p><strong>Serveur à tester :</strong> <a href="'.e($url).'">'.e($url).'</a></p>';
            foreach (['preconditions'=>'Préconditions','steps'=>'Étapes de test','expected'=>'Résultat attendu'] as $key => $label) {
                if ($values[$key] !== '') $body .= '<p><strong>'.$label.'</strong></p><p>'.nl2br(e($values[$key])).'</p>';
            }
            $comment = Ticket::comment($ticket, $body, $actor, 'html');
            DB::run(
                'INSERT INTO ticket_test_procedures(comment_id,ticket_id,event_id,submission_key,request_hash) VALUES(?,?,?,?,?)',
                [$comment, $ticket, $event, $nonce, $hash],
            );
            return $comment;
        });
    }
}
