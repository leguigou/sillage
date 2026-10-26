<?php
declare(strict_types=1);
namespace App\Core;
final class Mailer
{
    public static function send(string $to, string $subject, string $body): void
    {
        if (preg_match('/[\r\n]/', $to . $subject . env('MAIL_FROM'))) {
            throw new \InvalidArgumentException('Adresse de messagerie invalide.');
        }
        if (env('MAIL_TRANSPORT') === 'log' && env('APP_ENV') === 'local') {
            $dir = ROOT . '/storage/mail';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            file_put_contents(
                $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt',
                "To: $to\nSubject: $subject\n\n$body",
                LOCK_EX,
            );
            return;
        }
        if (
            !mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, [
                'From' => env('MAIL_FROM'),
                'Content-Type' => 'text/plain; charset=UTF-8',
            ])
        ) {
            throw new \RuntimeException(
                'L’envoi de l’e-mail est indisponible. Contactez l’administrateur.',
            );
        }
    }
}
