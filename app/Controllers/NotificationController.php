<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,DB,Mailer,Security};
use App\Models\ProjectEmail;

final class NotificationController
{
    public function project(string $id): void
    {
        Auth::admin();ProjectEmail::save((int)$id,$_POST);
        flash('Les notifications e-mail de ce projet sont enregistrées.');
        redirect('/settings?project='.(int)$id.'#notifications');
    }
    public function preference(): void
    {
        $user=Auth::requireUser();
        DB::transaction(function() use($user) {
            DB::run('UPDATE users SET ticket_email_enabled=? WHERE id=?',[(int)!empty($_POST['ticket_email_enabled']),$user['id']]);
            if (empty($_POST['ticket_email_enabled'])) DB::run("UPDATE notification_outbox SET state='cancelled' WHERE recipient_id=? AND state IN ('pending','failed')",[$user['id']]);
        });
        flash('Votre préférence de notifications est enregistrée. Les e-mails de sécurité restent actifs.');
        redirect('/settings#email-preferences');
    }
    public function test(): void
    {
        $user=Auth::admin();Security::limit('mail-test-'.$user['id'],3,600);
        try {
            Mailer::send($user['email'],'Sillage — test de messagerie','Bonjour '.$user['name'].",\n\nCe message confirme que le transport de messagerie configuré a accepté l’envoi depuis Sillage.\n\n".url('/settings#notifications'));
        } catch (\RuntimeException) {
            flash('Le transport n’a pas accepté l’e-mail de test. Vérifiez MAIL_TRANSPORT, MAIL_FROM et la messagerie PHP du serveur.','error');
            redirect('/settings#notifications');
        }
        flash(env('MAIL_TRANSPORT')==='log'?'E-mail de test enregistré dans storage/mail (mode local).':'E-mail de test accepté par le transport. Vérifiez votre boîte de réception.');
        redirect('/settings#notifications');
    }
    public function retry(string $id): void
    {
        Auth::admin();
        \App\Models\Project::find((int)$id);
        DB::run("UPDATE notification_outbox SET state='pending',attempts=0,available_at=UTC_TIMESTAMP(),last_error=NULL WHERE project_id=? AND state='failed'",[(int)$id]);
        flash('Les notifications en échec sont remises en attente.');
        redirect('/settings?project='.(int)$id.'#notifications');
    }
}
