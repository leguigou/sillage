<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class Query
{
    public const JOIN = ' FROM tickets t JOIN projects p ON p.id=t.project_id LEFT JOIN users a ON a.id=t.assignee_id LEFT JOIN users u ON u.id=t.reporter_id ';
    public static function tickets(
        int $project,
        array $filters,
        int $limit = 100,
        int $offset = 0,
    ): array {
        [$where, $args] = Filter::where($project, $filters);
        $sorts = [
            'rank' => 't.rank_value',
            'title' => 't.title',
            'status' => 't.status',
            'priority' => "FIELD(t.priority,'highest','high','medium','low','lowest')",
            'due' => 't.due_date',
            'start' => 't.start_date',
            'created' => 't.created_at',
            'updated' => 't.updated_at',
            'assignee' => 'a.name',
            'type' => 't.type',
        ];
        $order = $sorts[$filters['sort'] ?? 'rank'] ?? $sorts['rank'];
        $dir = ($filters['direction'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $rows = DB::all(
            'SELECT t.*,p.project_key,a.name assignee_name,u.name reporter_name' .
                self::JOIN .
                "WHERE $where ORDER BY $order $dir,t.id ASC LIMIT " .
                max(1, min(2000, $limit)) .
                ' OFFSET ' .
                max(0, $offset),
            $args,
        );
        foreach ($rows as &$row) {
            $row['key'] = $row['project_key'] . '-' . $row['number'];
            $row['labels_array'] = json_decode($row['labels'], true);
        }
        return $rows;
    }
    public static function count(int $project, array $filters): int
    {
        [$w, $p] = Filter::where($project, $filters);
        return (int) DB::one('SELECT COUNT(*) n' . self::JOIN . 'WHERE ' . $w, $p)['n'];
    }
    public static function aggregate(int $project, array $filters, string $field): array
    {
        $col = match ($field) {
            'type' => 't.type',
            'priority' => 't.priority',
            'assignee' => "COALESCE(a.name,'Non assigné')",
            default => 't.status',
        };
        [$w, $p] = Filter::where($project, $filters);
        return DB::all(
            "SELECT $col label,COUNT(*) n" . self::JOIN . "WHERE $w GROUP BY $col ORDER BY n DESC",
            $p,
        );
    }
}
