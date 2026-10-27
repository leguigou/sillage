<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, Security, View};
use App\Models\{Project, Ticket};
final class FormController
{
    public const FIELDS = [
        'title' => 'Résumé',
        'description' => 'Description',
        'priority' => 'Priorité',
        'due_date' => 'Date souhaitée',
        'attachment' => 'Pièce jointe',
    ];
    public function save(): void
    {
        $u = Auth::admin();
        $pid = (int) ($_POST['project_id'] ?? 0);
        Project::find($pid);
        $title = trim((string) ($_POST['title'] ?? ''));
        if (!$title || mb_strlen($title) > 160) {
            throw new \InvalidArgumentException('Le titre est requis (160 caractères maximum).');
        }
        $type = (string) ($_POST['ticket_type'] ?? 'task');
        if (!isset(Ticket::TYPES[$type]) || $type === 'subtask') {
            throw new \InvalidArgumentException(
                'Type de ticket invalide pour un formulaire externe.',
            );
        }
        $fields = [];
        $selected = (array) ($_POST['fields'] ?? []);
        array_unshift($selected, 'title');
        foreach (array_unique($selected) as $key) {
            if (isset(self::FIELDS[$key])) {
                $conditionField = (string) ($_POST['condition_field'][$key] ?? '');
                if ($conditionField && $conditionField !== 'priority') {
                    throw new \InvalidArgumentException(
                        'Seules les conditions sur la priorité sont prises en charge.',
                    );
                }
                if ($conditionField && !in_array('priority', $selected, true)) {
                    throw new \InvalidArgumentException(
                        'Ajoutez le champ Priorité avant de l’utiliser dans une condition.',
                    );
                }
                if (
                    $conditionField &&
                    !isset(Ticket::PRIORITIES[(string) ($_POST['condition_value'][$key] ?? 'high')])
                ) {
                    throw new \InvalidArgumentException('Priorité de condition invalide.');
                }
                $fields[] = [
                    'key' => $key,
                    'label' => mb_substr(
                        trim((string) ($_POST['labels'][$key] ?? self::FIELDS[$key])),
                        0,
                        120,
                    ),
                    'required' =>
                        $key === 'title' ||
                        in_array($key, (array) ($_POST['required'] ?? []), true),
                    'condition' =>
                        $key === 'title' || !$conditionField
                            ? null
                            : [
                                'field' => 'priority',
                                'value' => (string) ($_POST['condition_value'][$key] ?? 'high'),
                            ],
                ];
            }
        }
        $description = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 5000);
        $json = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $old = DB::one('SELECT * FROM public_forms WHERE id=? AND project_id=?', [$id, $pid]);
            if (!$old) {
                throw new \InvalidArgumentException('Formulaire introuvable.');
            }
            DB::run(
                'UPDATE public_forms SET title=?,description=?,fields_json=?,ticket_type=? WHERE id=?',
                [$title, $description, $json, $type, $id],
            );
        } else {
            $token = bin2hex(random_bytes(32));
            $id = DB::insert(
                'INSERT INTO public_forms(project_id,title,description,token_hash,fields_json,ticket_type,reporter_id) VALUES(?,?,?,?,?,?,?)',
                [$pid, $title, $description, hash('sha256', $token), $json, $type, $u['id']],
            );
            $_SESSION['new_form_link'] = ['id' => $id, 'url' => url('/f/' . $token)];
        }
        flash('Formulaire enregistré. Le lien autorise uniquement l’envoi d’une demande.');
        redirect('/forms?project=' . $pid);
    }
    public function toggle(string $id): void
    {
        Auth::admin();
        DB::run('UPDATE public_forms SET enabled=1-enabled WHERE id=?', [(int) $id]);
        flash('Disponibilité du formulaire modifiée.');
        redirect('/forms');
    }
    public function rotate(string $id): void
    {
        Auth::admin();
        $f = DB::one('SELECT * FROM public_forms WHERE id=?', [(int) $id]);
        if (!$f) {
            throw new \InvalidArgumentException('Formulaire introuvable.');
        }
        $token = bin2hex(random_bytes(32));
        DB::run('UPDATE public_forms SET token_hash=? WHERE id=?', [
            hash('sha256', $token),
            (int) $id,
        ]);
        $_SESSION['new_form_link'] = ['id' => $id, 'url' => url('/f/' . $token)];
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
