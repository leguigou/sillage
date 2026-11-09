<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
use App\Models\Ticket;
/**
 * Read-side ticket queries for lists, exports, reports and MCP tools: ticket pages, counts, team workload
 * and per-field aggregates. Every method takes its WHERE clause from Filter::where(), whose bound
 * parameters carry all user values. ORDER BY / GROUP BY columns come from fixed maps here (sort keys
 * are validated by ListLayout::sorts()), never from raw input.
 */
final class Query
{
    /** Shared FROM/JOIN clause; defines the aliases (t, p, a, u, tm) that Filter::FIELDS relies on. */
    public const JOIN = ' FROM tickets t JOIN projects p ON p.id=t.project_id LEFT JOIN users a ON a.id=t.assignee_id LEFT JOIN users u ON u.id=t.reporter_id LEFT JOIN teams tm ON tm.id=t.team_id ';
    public static function tickets(
        int $project,
        array $filters,
        int $limit = 100,
        int $offset = 0,
    ): array {
        [$where, $args] = Filter::where($project, $filters);
        $sorts = [
            'rank' => 't.rank_value',
            // Project key then the numeric ticket number: TMA-2 sorts before TMA-10, unlike a text sort.
            'key' => ['p.project_key', 't.number'],
            'title' => 't.title',
            'status' => 't.status',
            'priority' => "FIELD(t.priority,'".implode("','", array_keys(Ticket::PRIORITIES))."')",
            'due' => 't.due_date',
            'estimated_hours' => 't.estimated_hours',
            'progress_percent' => 't.progress_percent',
            'start' => 't.start_date',
            'created' => 't.created_at',
            'updated' => 't.updated_at',
            'assignee' => 'a.name',
            'team' => 'tm.name',
            'type' => 't.type',
        ];
        $order=[];
        foreach(ListLayout::sorts($filters) as $field=>$direction) foreach((array)$sorts[$field] as $column) $order[]=$column.' '.strtoupper($direction);
        $order=implode(',',$order?:['t.rank_value ASC']);
        // t.id is appended as a final tie-breaker so LIMIT/OFFSET paging is stable.
        $rows = DB::all(
            'SELECT t.*,'.\App\Models\TicketTime::selectSql().',p.project_key,a.name assignee_name,u.name reporter_name,tm.name team_name' .
                self::JOIN .
                "WHERE $where ORDER BY $order,t.id ASC LIMIT " .
                max(1, min(2000, $limit)) .
                ' OFFSET ' .
                max(0, $offset),
            $args,
        );
        // Git indicators are loaded in bulk for the page rather than per row.
        $conflicts=\App\Models\DevelopmentEvent::conflictsForTickets(array_column($rows,'id'));
        $branchProviders=\App\Models\TicketBranch::providersForTickets(array_column($rows,'id'));
        foreach ($rows as &$row) {
            $row['branch_providers']=$branchProviders[$row['id']]??[];
            $row['has_merge_conflict']=!empty($conflicts[$row['id']]);
            $row['key'] = $row['project_key'] . '-' . $row['number'];
            $row['labels_array'] = json_decode($row['labels'], true);
            $row=\App\Models\Workflow::decorate($row);
        }
        return $rows;
    }
    public static function count(int $project, array $filters): int
    {
        [$w, $p] = Filter::where($project, $filters);
        return (int) DB::one('SELECT COUNT(*) n' . self::JOIN . 'WHERE ' . $w, $p)['n'];
    }
    /** Per-team totals (ticket count, done count, average progress, estimated/spent/remaining hours). */
    public static function teamWorkload(int $project,array $filters): array
    {
        [$where,$args]=Filter::where($project,$filters);
        return DB::all("SELECT t.team_id,COALESCE(tm.name,'Sans équipe') name,COUNT(*) total,SUM(".\App\Models\Workflow::categorySql()."='done') done,AVG(t.progress_percent) progress,SUM(t.estimated_hours) hours,SUM(".\App\Models\TicketTime::spentSql().") spent_hours,SUM(".\App\Models\TicketTime::remainingSql().") remaining_hours,SUM(t.estimated_hours IS NULL) unestimated".self::JOIN."WHERE $where GROUP BY t.team_id,tm.name ORDER BY name,t.team_id",$args);
    }
    /** Ticket counts grouped by one field (unknown field names fall back to status); status/type/priority rows also get their display colour. */
    public static function aggregate(int $project, array $filters, string $field): array
    {
        $col = match ($field) {
            'type' => 't.type',
            'priority' => 't.priority',
            'assignee' => "COALESCE(a.name,'Non assigné')",
            'team' => "COALESCE(tm.name,'Sans équipe')",
            default => 't.status',
        };
        [$w, $p] = Filter::where($project, $filters);
        $rows=DB::all(
            "SELECT $col label,COUNT(*) n" . self::JOIN . "WHERE $w GROUP BY $col ORDER BY n DESC",
            $p,
        );
        if (in_array($field,['status','type','priority'],true)) foreach($rows as &$row) $row['color']=$field==='priority'?\App\Models\Appearance::mark('priority',$row['label'])['color']:\App\Models\Workflow::mark($field,$project,$row['label'])['color'];
        return $rows;
    }
}
