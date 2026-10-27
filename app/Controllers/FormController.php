<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, Security, View};
use App\Models\{Project, Ticket};
final class FormController
{
    public function save(): void
    {
        $u = Auth::admin();
        $input = $_POST;
        $input['fields'] = (array) ($_POST['fields'] ?? []);
        $input['required'] = (array) ($_POST['required'] ?? []);
        $form = \App\Models\PublicForm::save($input, (int) $u['id']);
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
    public function submit(string $token): void
    {
        Security::limit('public-form', 10, 3600);
        $form = $this->find($token);
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
