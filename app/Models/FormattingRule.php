<?php
declare(strict_types=1);
namespace App\Models;
use App\Core\DB;
final class FormattingRule
{
    public static function save(array $input): int
    {
        $project = (int) ($input['project_id'] ?? 0);
        Project::find($project);
        $field = (string) ($input['field'] ?? '');
        $value = (string) ($input['value'] ?? '');
        $title = trim((string) ($input['title'] ?? ''));
        $color = (string) ($input['color'] ?? '');
        $options = [
            'status' => Ticket::STATUSES,
            'priority' => Ticket::PRIORITIES,
            'type' => Ticket::TYPES,
            'flagged' => ['0' => 'Non', '1' => 'Oui'],
        ];
        if (
            !isset($options[$field][$value]) ||
            !$title ||
            mb_strlen($title) > 120 ||
            !preg_match('/^#[a-f0-9]{6}$/iD', $color)
        ) {
            throw new \InvalidArgumentException(
                'Champ, valeur, titre ou couleur de mise en forme invalide.',
            );
        }
        return DB::insert(
            'INSERT INTO formatting_rules(project_id,field,match_value,color,title) VALUES(?,?,?,?,?)',
            [$project, $field, $value, $color, $title],
        );
    }
    public static function delete(int $id): void
    {
        if (!DB::run('DELETE FROM formatting_rules WHERE id=?', [$id])->rowCount()) {
            throw new \InvalidArgumentException('Règle introuvable.');
        }
    }
}
