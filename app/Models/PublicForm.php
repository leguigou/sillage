<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
final class PublicForm
{
    public const FIELDS = [
        'title' => 'Résumé',
        'description' => 'Description',
        'priority' => 'Priorité',
        'due_date' => 'Date souhaitée',
        'attachment' => 'Pièce jointe',
    ];
    public static function save(array $input, int $actor): array
    {
        return DB::transaction(function () use ($input, $actor) {
            $id = (int) ($input['id'] ?? 0);
            if ($id) {
                $old = self::find($id, true);
                if ((int) ($input['project_id'] ?? 0) !== (int) $old['project_id']) {
                    throw new \InvalidArgumentException(
                        'Le formulaire doit rester dans son projet.',
                    );
                }
                $defaults = [
                    'description' => $old['description'],
                    'ticket_type' => $old['ticket_type'],
                    'fields' => array_column($old['fields'], 'key'),
                    'required' => [],
                    'labels' => [],
                    'condition_field' => [],
                    'condition_value' => [],
                ];
                foreach ($old['fields'] as $f) {
                    if ($f['required']) {
                        $defaults['required'][] = $f['key'];
                    }
                    $defaults['labels'][$f['key']] = $f['label'];
                    $defaults['condition_field'][$f['key']] = $f['condition']['field'] ?? '';
                    $defaults['condition_value'][$f['key']] = $f['condition']['value'] ?? 'high';
                }
                foreach (['labels', 'condition_field', 'condition_value'] as $map) {
                    $input[$map] = array_replace($defaults[$map], $input[$map] ?? []);
                }
                $input = array_replace($defaults, $input);
            }
            $link = null;
            $pid = (int) ($input['project_id'] ?? 0);
            Project::find($pid);
            $title = trim((string) ($input['title'] ?? ''));
            if (!$title || mb_strlen($title) > 160) {
                throw new \InvalidArgumentException(
                    'Le titre est requis (160 caractères maximum).',
                );
            }
            $type = (string) ($input['ticket_type'] ?? 'task');
            if (!isset(Ticket::TYPES[$type]) || $type === 'subtask') {
                throw new \InvalidArgumentException(
                    'Type de ticket invalide pour un formulaire externe.',
                );
            }
            $fields = [];
            $selected = (array) ($input['fields'] ?? []);
            array_unshift($selected, 'title');
            if (
                array_filter(
                    $selected,
                    fn($field) => !is_string($field) || !isset(self::FIELDS[$field]),
                )
            ) {
                throw new \InvalidArgumentException('Champ de formulaire inconnu.');
            }
            foreach (array_unique($selected) as $key) {
                if (isset(self::FIELDS[$key])) {
                    $conditionField = (string) ($input['condition_field'][$key] ?? '');
                    if ($conditionField && $key === 'priority') {
                        throw new \InvalidArgumentException(
                            'Le champ Priorité ne peut pas dépendre de sa propre valeur.',
                        );
                    }
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
                        !isset(
                            Ticket::PRIORITIES[
                                (string) ($input['condition_value'][$key] ?? 'high')
                            ],
                        )
                    ) {
                        throw new \InvalidArgumentException('Priorité de condition invalide.');
                    }
                    $fields[] = [
                        'key' => $key,
                        'label' => mb_substr(
                            trim((string) ($input['labels'][$key] ?? self::FIELDS[$key])),
                            0,
                            120,
                        ),
                        'required' =>
                            $key === 'title' ||
                            in_array($key, (array) ($input['required'] ?? []), true),
                        'condition' =>
                            $key === 'title' || !$conditionField
                                ? null
                                : [
                                    'field' => 'priority',
                                    'value' => (string) ($input['condition_value'][$key] ?? 'high'),
                                ],
                    ];
                }
            }
            $description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 5000);
            $json = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            if ($id) {
                DB::run(
                    'UPDATE public_forms SET title=?,description=?,fields_json=?,ticket_type=? WHERE id=?',
                    [$title, $description, $json, $type, $id],
                );
            } else {
                $token = bin2hex(random_bytes(32));
                $id = DB::insert(
                    'INSERT INTO public_forms(project_id,title,description,token_hash,fields_json,ticket_type,reporter_id) VALUES(?,?,?,?,?,?,?)',
                    [$pid, $title, $description, hash('sha256', $token), $json, $type, $actor],
                );
                $link = url('/f/' . $token);
            }
            return self::find($id) + ($link ? ['public_url' => $link] : []);
        });
    }
    public static function find(int $id, bool $lock = false): array
    {
        $form = DB::one(
            'SELECT id,project_id,title,description,fields_json,ticket_type,enabled,reporter_id,created_at FROM public_forms WHERE id=?' .
                ($lock ? ' FOR UPDATE' : ''),
            [$id],
        );
        if (!$form) {
            throw new \InvalidArgumentException('Formulaire introuvable.');
        }
        $form['fields'] = json_decode($form['fields_json'], true, 64, JSON_THROW_ON_ERROR);
        unset($form['fields_json']);
        return $form;
    }
    public static function enabled(int $id, bool $enabled): array
    {
        return DB::transaction(function () use ($id, $enabled) {
            self::find($id, true);
            DB::run('UPDATE public_forms SET enabled=? WHERE id=?', [(int) $enabled, $id]);
            return self::find($id);
        });
    }
    public static function rotate(int $id): array
    {
        return DB::transaction(function () use ($id) {
            self::find($id, true);
            $token = bin2hex(random_bytes(32));
            DB::run('UPDATE public_forms SET token_hash=? WHERE id=?', [
                hash('sha256', $token),
                $id,
            ]);
            return ['id' => $id, 'public_url' => url('/f/' . $token)];
        });
    }
    public static function delete(int $id): void
    {
        if (!DB::run('DELETE FROM public_forms WHERE id=?', [$id])->rowCount()) {
            throw new \InvalidArgumentException('Formulaire introuvable.');
        }
    }
}
