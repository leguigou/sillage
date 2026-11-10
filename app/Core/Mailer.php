<?php
declare(strict_types=1);
namespace App\Core;
/**
 * Low-level e-mail sending, used for account e-mails and by the notification queue
 * (App\Services\Notifications). A message always has a plain-text body; when an HTML body is given
 * (built by App\Services\EmailTemplate) it is sent as multipart/alternative, so clients that do
 * not display HTML still show the text version. Two transports, chosen by MAIL_TRANSPORT: "mail"
 * (PHP mail(), the default) and "log" (writes the message to storage/mail/*.txt, plus a sibling
 * .html preview), which is only allowed with APP_ENV=local. Recipient, subject and MAIL_FROM are
 * validated and must not contain CR/LF, which prevents e-mail header injection. Parts are
 * base64-encoded, so their content can never terminate the MIME boundary or a header.
 * Failures throw rather than being silently ignored.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body, ?string $html = null): void
    {
        $transport=env('MAIL_TRANSPORT','mail');
        if (!in_array($transport,['log','mail'],true) || ($transport==='log' && env('APP_ENV')!=='local')) throw new \RuntimeException('Transport de messagerie invalide.');
        if (!filter_var($to,FILTER_VALIDATE_EMAIL) || !filter_var(env('MAIL_FROM'),FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Adresse de messagerie invalide.');
        if (preg_match('/[\r\n]/', $to . $subject . env('MAIL_FROM'))) {
            throw new \InvalidArgumentException('Adresse de messagerie invalide.');
        }
        $from = self::from();
        if (env('MAIL_TRANSPORT') === 'log' && env('APP_ENV') === 'local') {
            $dir = ROOT . '/storage/mail';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $base = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
            // The HTML preview is written first so a reader that finds the .txt also finds its .html.
            if ($html !== null && file_put_contents($base . '.html', $html, LOCK_EX) === false) {
                throw new \RuntimeException('Impossible d’enregistrer l’e-mail local.');
            }
            $written=file_put_contents($base . '.txt', 'From: ' . self::senderName() . ' <' . env('MAIL_FROM') . ">\nTo: $to\nSubject: $subject\n\n$body", LOCK_EX);
            if ($written===false) throw new \RuntimeException('Impossible d’enregistrer l’e-mail local.');
            return;
        }
        $headers = ['From' => $from, 'MIME-Version' => '1.0'];
        if ($html === null) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $content = $body;
        } else {
            $boundary = '=_' . bin2hex(random_bytes(16));
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $part = fn(string $type, string $data) => '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $type . "; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($data), 76, "\r\n");
            $content = "Ce message est au format MIME.\r\n\r\n"
                . $part('text/plain', $body)
                . $part('text/html', $html)
                . '--' . $boundary . "--\r\n";
        }
        if (!mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $content, $headers)) {
            throw new \RuntimeException(
                'L’envoi de l’e-mail est indisponible. Contactez l’administrateur.',
            );
        }
    }
    /** Sender display name: the one chosen in the settings, otherwise the software name. */
    public static function senderName(): string
    {
        $name = \App\Models\CompanyBranding::senderName() ?? app_name();
        // Defence in depth: the name is validated on save, but must never break the header.
        return preg_match('/[\x00-\x1f\x7f"<>]/u', $name) ? app_name() : $name;
    }
    /** "Name <address>" with the name RFC 2047-encoded, so accents and punctuation are safe. */
    private static function from(): string
    {
        return '=?UTF-8?B?' . base64_encode(self::senderName()) . '?= <' . env('MAIL_FROM') . '>';
    }
}
