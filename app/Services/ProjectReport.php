<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\DB;

final class ProjectReport
{
    public static function activity(int $project, array $filters, ReportPeriod $period): array
    {
        [$where, $args] = Filter::where($project, $filters);
        // A site import can bring history older than the destination's migration date.
        $historySince = DB::one(
            'SELECT LEAST(applied_at, COALESCE((SELECT created_at FROM ticket_history ORDER BY id LIMIT 1), applied_at)) coverage
            FROM migrations WHERE version=?',
            ['019_time_history_releases.sql'],
        )['coverage'];
        $intervals = $period->intervals();

        // First extract only the scalar fields that are needed for historical transitions.
        // Logged resolution goes from null to a value on closure and back to null on reopening.
        // Each logged progress change gives the value before it, even after the selected period.
        $rows = DB::all(
            "WITH periods AS (
                SELECT period_number, starts, ends FROM JSON_TABLE(?, '$[*]' COLUMNS(
                    period_number FOR ORDINALITY, starts DATETIME PATH '$[0]', ends DATETIME PATH '$[1]'
                )) AS period_rows
            ), cohort AS (
                SELECT t.id, t.created_at, t.completed_at, t.progress_percent " . Query::JOIN . "
                WHERE $where AND t.created_at < (SELECT ends FROM periods WHERE period_number=1)
            ), audit_fields AS (
                SELECT h.id, h.ticket_id, h.created_at, h.action, fields.field_name,
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(h.changes_json, CONCAT('$[',fields.change_index-1,'].before'))),'null') previous_value,
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(h.changes_json, CONCAT('$[',fields.change_index-1,'].after'))),'null') next_value
                FROM cohort c JOIN ticket_history h ON h.ticket_id=c.id
                CROSS JOIN JSON_TABLE(h.changes_json, '$[*]' COLUMNS(
                    change_index FOR ORDINALITY, field_name VARCHAR(40) PATH '$.field'
                )) AS fields
                WHERE h.created_at >= (SELECT starts FROM periods WHERE period_number=1)
                    AND fields.field_name IN ('status','resolution','progress_percent')
            ), closures AS (
                SELECT ticket_id, created_at FROM audit_fields
                WHERE field_name='resolution' AND previous_value IS NULL AND next_value IS NOT NULL
            ), events AS (
                SELECT id ticket_id, created_at happened_at, 'created' kind FROM cohort
                UNION ALL
                SELECT h.ticket_id, h.created_at, 'modified'
                FROM cohort c JOIN ticket_history h ON h.ticket_id=c.id
                WHERE h.action IN ('updated','validation.approved','validation.rejected')
                    AND JSON_LENGTH(h.changes_json)>0
                    AND h.created_at >= (SELECT starts FROM periods WHERE period_number=1)
                    AND h.created_at < (SELECT ends FROM periods WHERE period_number=1)
                UNION ALL
                SELECT ticket_id, created_at, 'treated' FROM audit_fields
                WHERE action<>'created' AND field_name IN ('status','progress_percent')
                UNION ALL
                SELECT ticket_id, created_at, 'closed' FROM closures
                UNION ALL
                SELECT c.id, c.completed_at, 'closed' FROM cohort c
                WHERE c.completed_at >= (SELECT starts FROM periods WHERE period_number=1)
                    AND NOT EXISTS(SELECT 1 FROM closures cl WHERE cl.ticket_id=c.id AND cl.created_at>=c.completed_at)
                UNION ALL
                SELECT ticket_id, created_at, 'reopened' FROM audit_fields
                WHERE field_name='resolution' AND previous_value IS NOT NULL AND next_value IS NULL
            ), activity AS (
                SELECT p.period_number,
                    COUNT(DISTINCT CASE WHEN e.kind='created' THEN e.ticket_id END) created,
                    COUNT(DISTINCT CASE WHEN e.kind='modified' THEN e.ticket_id END) modified,
                    COUNT(DISTINCT CASE WHEN e.kind IN ('treated','closed') THEN e.ticket_id END) treated,
                    COUNT(DISTINCT CASE WHEN e.kind='closed' THEN e.ticket_id END) closed,
                    COUNT(DISTINCT CASE WHEN e.kind='reopened' THEN e.ticket_id END) reopened
                FROM periods p JOIN events e ON e.happened_at>=p.starts AND e.happened_at<p.ends
                GROUP BY p.period_number
            ), progress_changes AS (
                SELECT ticket_id, created_at, previous_value,
                    LAG(created_at) OVER (PARTITION BY ticket_id ORDER BY created_at,id) lower_bound
                FROM audit_fields WHERE field_name='progress_percent'
            ), latest_progress AS (
                SELECT ticket_id, MAX(created_at) lower_bound FROM progress_changes GROUP BY ticket_id
            ), progress_intervals AS (
                SELECT ticket_id, lower_bound, created_at upper_bound, CAST(previous_value AS UNSIGNED) progress
                FROM progress_changes WHERE previous_value IS NOT NULL
                UNION ALL
                SELECT c.id, lp.lower_bound, NULL, c.progress_percent
                FROM cohort c LEFT JOIN latest_progress lp ON lp.ticket_id=c.id
            ), snapshots AS (
                SELECT p.period_number, ROUND(AVG(i.progress),1) progress
                FROM periods p
                JOIN cohort c ON c.created_at<p.ends
                JOIN progress_intervals i ON i.ticket_id=c.id
                    AND (i.lower_bound IS NULL OR i.lower_bound<p.ends)
                    AND (i.upper_bound IS NULL OR i.upper_bound>=p.ends)
                WHERE p.ends>?
                GROUP BY p.period_number
            ), time_spent AS (
                SELECT p.period_number, SUM(w.hours) hours
                FROM periods p JOIN ticket_worklogs w ON w.worked_on>=p.starts AND w.worked_on<p.ends AND w.deleted=0
                JOIN cohort c ON c.id=w.ticket_id
                GROUP BY p.period_number
            )
            SELECT DATE(p.starts) starts, DATE_SUB(DATE(p.ends),INTERVAL 1 DAY) ends,
                COALESCE(a.created,0) created, COALESCE(a.modified,0) modified, COALESCE(a.treated,0) treated,
                COALESCE(a.closed,0) closed, COALESCE(a.reopened,0) reopened,
                s.progress, COALESCE(w.hours,0) hours
            FROM periods p
            LEFT JOIN activity a ON a.period_number=p.period_number
            LEFT JOIN snapshots s ON s.period_number=p.period_number
            LEFT JOIN time_spent w ON w.period_number=p.period_number
            ORDER BY p.period_number",
            [json_encode($intervals, JSON_THROW_ON_ERROR), ...$args, $historySince],
        );

        return [
            'total'=>array_shift($rows),
            'series'=>$rows,
            'historySince'=>substr($historySince, 0, 10),
            'partialHistory'=>$intervals[0][0] < $historySince,
        ];
    }
}
