<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth,DB,Mailer,Security};
use App\Models\ProjectEmail;

/**
 * E-mail notification settings (forms in /settings).
 *
 * - POST /projects/{id}/notifications (admin): project e-mail settings, saved
 *   by App\Models\ProjectEmail.
 * - POST /projects/{id}/notifications/retry (admin): re-queues the project's
 *   failed messages in notification_outbox.
 * - POST /account/notifications (any signed-in user): personal opt-in/out of
 *   ticket e-mails.
 * - POST /notifications/test (admin, max 3 per 10 min): sends a test e-mail
 *   to the admin to check the mail transport.
 */
final class NotificationController
{
    /** Saves the e-mail notification settings of project {id}. */
    public function project(string $id): void
    {
        Auth::admin();ProjectEmail::save((int)$id,$_POST);
        flash('Les notifications e-mail de ce projet sont enregistrées.');
        redirect('/settings?project='.(int)$id.'#notifications');
    }
    /** Turns ticket e-mails on/off for the current user; opting out also cancels their queued unsent e-mails. */
    public function preference(): void
    {
        $user=Auth::requireUser();
        DB::transaction(function() use($user) {
            DB::run('UPDATE users SET ticket_email_enabled=? WHERE id=?',[(int)!empty($_POST['ticket_email_enabled']),$user['id']]);
            if (empty($_POST['ticket_email_enabled'])) DB::run("UPDATE notification_outbox
                SET state='cancelled'
                WHERE recipient_id=? AND state IN ('pending','failed')",[$user['id']]);
        });
        flash('Votre préférence de notifications est enregistrée. Les e-mails de sécurité restent actifs.');
        redirect('/settings#email-preferences');
    }
    public function test(): void
    {
        $user=Auth::admin();Security::limit('mail-test-'.$user['id'],3,600);
        try {
            $mail=\App\Services\EmailTemplate::render([
                'preheader'=>'Le transport de messagerie fonctionne.',
                'eyebrow'=>'Test de messagerie',
                'title'=>'Tout fonctionne !',
                'greeting'=>'Bonjour '.$user['name'].',',
                'paragraphs'=>['Ce message confirme que le transport de messagerie configuré a accepté l’envoi depuis '.app_name().'. Les notifications et les e-mails de compte utilisent cette même mise en page.'],
                'action'=>['label'=>'Retour aux paramètres','url'=>url('/settings#notifications')],
            ]);
            Mailer::send($user['email'],app_name().' — test de messagerie',$mail['text'],$mail['html']);
        } catch (\RuntimeException) {
            flash('Le transport n’a pas accepté l’e-mail de test. Vérifiez MAIL_TRANSPORT, MAIL_FROM et la messagerie PHP du serveur.','error');
            redirect('/settings#notifications');
        }
        flash(env('MAIL_TRANSPORT')==='log'?'E-mail de test enregistré dans storage/mail (mode local).':'E-mail de test accepté par le transport. Vérifiez votre boîte de réception.');
        redirect('/settings#notifications');
    }
    /** Resets failed outbox messages of project {id} to "pending" (attempts reset) so they are sent again. */
    public function retry(string $id): void
    {
        Auth::admin();
        // Throws "Projet introuvable." if the project does not exist.
        \App\Models\Project::find((int)$id);
        DB::run("UPDATE notification_outbox
            SET state='pending',attempts=0,available_at=UTC_TIMESTAMP(),last_error=NULL
            WHERE project_id=? AND state='failed'",[(int)$id]);
        flash('Les notifications en échec sont remises en attente.');
        redirect('/settings?project='.(int)$id.'#notifications');
    }
}
