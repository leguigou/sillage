<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, Security, View};
use App\Models\{Project, Ticket};
/**
 * Public request forms: anyone with the secret link can submit a ticket into a
 * project without an account.
 *
 * Admin management (forms screen, /forms):
 * - POST /forms (create/update), /forms/{id}/toggle (enable/disable),
 *   /forms/{id}/rotate (new secret link, the old one stops working).
 * Public, no login (/f/{token}, token = 64 hex chars, stored only as a SHA-256 hash):
 * - GET shows the form, POST creates the ticket (rate-limited per IP), with an
 *   optional attachment stored through AttachmentController::store().
 * Form definitions live in App\Models\PublicForm.
 */
final class FormController
{
    public function save(): void
    {
        $u = Auth::admin();
        $input = $_POST;
        $input['fields'] = (array) ($_POST['fields'] ?? []);
        $input['required'] = (array) ($_POST['required'] ?? []);
        $form = \App\Models\PublicForm::save($input, (int) $u['id']);
        // A public URL is only returned when a new token was generated; it is
        // shown once on the next page via the session (only its hash is stored).
        if (isset($form['public_url'])) {
            $_SESSION['new_form_link'] = ['id' => $form['id'], 'url' => $form['public_url']];
        }
        flash('Formulaire enregistré. Le lien autorise uniquement l’envoi d’une demande.');
        redirect('/forms?project=' . $form['project_id']);
    }
    public function toggle(string $id): void
    {
        Auth::admin();
        $form = \App\Models\PublicForm::find((int) $id);
        \App\Models\PublicForm::enabled((int) $id, !$form['enabled']);
        flash('Disponibilité du formulaire modifiée.');
        redirect('/forms');
    }
    public function rotate(string $id): void
    {
        Auth::admin();
        $form = \App\Models\PublicForm::rotate((int) $id);
        $_SESSION['new_form_link'] = ['id' => $form['id'], 'url' => $form['public_url']];
        flash('Nouveau lien créé. Le précédent ne fonctionne plus.');
        redirect('/forms');
    }
    /** Loads an enabled form from its public token, or ends the request with a 404. */
    private function find(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            http_response_code(404);
            exit('Formulaire introuvable.');
        }
        $f = DB::one(
            'SELECT f.*,p.name project_name FROM public_forms f JOIN projects p ON p.id=f.project_id WHERE token_hash=? AND enabled=1',
            [hash('sha256', $token)],
        );
        if (!$f) {
            http_response_code(404);
            exit('Ce formulaire n’est plus disponible.');
        }
        $f['fields'] = json_decode($f['fields_json'], true);
        return $f;
    }
    public function show(string $token): void
    {
        $form = $this->find($token);
        $title = $form['title'];
        View::render('public-form', [
            'guest' => true,
            'title' => $title,
            'form' => $form,
            'token' => $token,
            'submitted' => !empty($_SESSION['submitted'][$form['id']]),
        ]);
        unset($_SESSION['submitted'][$form['id']]);
    }
    /**
     * Creates a ticket from a public form submission. The ticket is reported by
     * the form's configured reporter and records the submitter's e-mail.
     */
    public function submit(string $token): void
    {
        // 10 submissions per IP per hour.
        Security::limit('public-form', 10, 3600);
        $form = $this->find($token);
        // Honeypot: "website" is hidden from humans, so a filled value means a bot.
        if (!empty($_POST['website'])) {
            http_response_code(422);
            exit('Demande refusée.');
        }
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new \InvalidArgumentException('Votre adresse e-mail est requise.');
        }
        $data = [
            'status' => 'todo',
            'type' => $form['ticket_type'],
            'labels' => ['form:' . $form['id']],
            'description' => '',
            'priority' => 'medium',
        ];
        // Only fields defined in the stored form are read from $_POST; a field with
        // a display condition is skipped when the condition is not met.
        foreach ($form['fields'] as $field) {
            $visible =
                !$field['condition'] ||
                (string) ($_POST[$field['condition']['field']] ?? 'medium') ===
                    $field['condition']['value'];
            if (!$visible) {
                continue;
            }
            if ($field['key'] === 'attachment') {
                if (
                    $field['required'] &&
                    ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE
                ) {
                    throw new \InvalidArgumentException('La pièce jointe est requise.');
                }
                continue;
            }
            $value = trim((string) ($_POST[$field['key']] ?? ''));
            if ($field['required'] && $value === '') {
                throw new \InvalidArgumentException(
                    'Le champ « ' . $field['label'] . ' » est requis.',
                );
            }
            if ($value !== '') {
                $data[$field['key']] = $value;
            }
        }
        $id = Ticket::create((int) $form['project_id'], $data, (int) $form['reporter_id'], $email);
        if (
            ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE &&
            array_filter($form['fields'], fn($f) => $f['key'] === 'attachment')
        ) {
            // The ticket already exists: an attachment failure only produces a warning.
            try {
                AttachmentController::store($id, $_FILES['attachment']);
            } catch (\Throwable $e) {
                flash(
                    'Votre demande a été créée, mais la pièce jointe n’a pas été ajoutée. Contactez votre interlocuteur ' .
                        \App\Core\Company::name() .
                        '.',
                    'error',
                );
            }
        }
        $_SESSION['submitted'][$form['id']] = true;
        redirect('/f/' . $token);
    }
}
