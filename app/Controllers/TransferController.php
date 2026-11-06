<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, View};
use App\Models\{Project, Ticket};
use App\Services\{Csv, Query};
/**
 * Ticket export (CSV/JSON) and CSV import.
 *
 * - GET /export (any signed-in user): downloads the tickets matching the
 *   current list filters (same query-string filters as the list, via
 *   App\Services\Query), up to 10,000 tickets.
 * - GET /import, POST /import/preview, POST /import (editors): two-step CSV
 *   import. The parsed file is kept in the session for preview, then the user
 *   maps CSV columns to ticket fields and confirms.
 */
final class TransferController
{
    /**
     * Streams the export in batches of 500 tickets. CSV starts with a UTF-8 BOM
     * (for Excel) and cells go through Csv::safe() against spreadsheet formula injection.
     */
    public function export(): void
    {
        Auth::requireUser();
        $pid = (int) ($_GET['project'] ?? 0);
        if ($pid) {
            Project::find($pid);
        }
        $count = Query::count($pid, $_GET);
        if ($count > 10000) {
            throw new \InvalidArgumentException(
                'Affinez les filtres pour exporter au maximum 10 000 tickets.',
            );
        }
        $format = ($_GET['format'] ?? 'csv') === 'json' ? 'json' : 'csv';
        header(
            'Content-Type: ' .
                ($format === 'json' ? 'application/json' : 'text/csv') .
                '; charset=utf-8',
        );
        header('Content-Disposition: attachment; filename="sillage-tickets.' . $format . '"');
        $f = fopen('php://output', 'wb');
        if ($format === 'csv') {
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv(
                $f,
                [
                    'key',
                    'title',
                    'description',
                    'type',
                    'status',
                    'priority',
                    'assignee',
                    'team_id',
                    'team',
                    'start_date',
                    'due_date',
                    'estimated_hours',
                    'progress_percent',
                    'spent_hours','remaining_hours','release_id','resolution','validation_state',
                    'labels',
                ],
                ',',
                '"',
                '',
            );
        } else {
            fwrite($f, '[');
        }
        $first = true;
        for ($offset = 0; $offset < $count; $offset += 500) {
            foreach (Query::tickets($pid, $_GET, 500, $offset) as $t) {
                $row = [
                    'key' => $t['key'],
                    'title' => $t['title'],
                    'description' => $t['description'],
                    'type' => $t['type'],
                    'status' => $t['status'],
                    'priority' => $t['priority'],
                    'assignee' => $t['assignee_name'],
                    'team_id' => $t['team_id'],
                    'team' => $t['team_name'],
                    'start_date' => $t['start_date'],
                    'due_date' => $t['due_date'],
                    'estimated_hours' => $t['estimated_hours'],
                    'progress_percent' => $t['progress_percent'],
                    'spent_hours'=>$t['spent_hours'],
                    'remaining_hours'=>$t['remaining_hours'],
                    'release_id'=>$t['release_id'],
                    'resolution'=>$t['resolution'],
                    'validation_state'=>$t['validation_state'],
                    'labels' => implode(', ', $t['labels_array']),
                ];
                if ($format === 'csv') {
                    fputcsv($f, array_map([Csv::class, 'safe'], array_values($row)), ',', '"', '');
                } else {
                    if (!$first) {
                        fwrite($f, ',');
                    }
                    fwrite($f, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                    $first = false;
                }
            }
        }
        if ($format === 'json') {
            fwrite($f, ']');
        }
        fclose($f);
    }
    /** Import screen, showing the pending preview (if any) stored in the session. */
    public function page(): void
    {
        $user = Auth::editor();
        $projects = Project::all();
        $project = Project::find(
            (int) ($_GET['project'] ?? ($_SESSION['project_id'] ?? $projects[0]['id'])),
        );
        $title = 'Importer des tickets';
        $section = 'list';
        $preview = $_SESSION['csv_preview'] ?? null;
        View::render(
            'import',
            compact('user', 'projects', 'project', 'title', 'section', 'preview'),
        );
    }
    /**
     * Step 1: parses the uploaded CSV (max 2 MB, see Csv::parse) and stores it
     * in the session with a random nonce that step 2 must send back.
     */
    public function preview(): void
    {
        Auth::editor();
        $pid = (int) ($_POST['project_id'] ?? 0);
        Project::find($pid);
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new \InvalidArgumentException('Sélectionnez un fichier CSV.');
        }
        $parsed = Csv::parse($f['tmp_name']);
        $_SESSION['csv_preview'] = $parsed + [
            'project_id' => $pid,
            'nonce' => bin2hex(random_bytes(16)),
        ];
        redirect('/import?project=' . $pid);
    }
    /**
     * Step 2: applies the column mapping ("map[field] = CSV header") to every
     * row, validates all rows first, then creates all tickets in one
     * transaction (all or nothing). Error messages give the CSV line number
     * (+2: header line and 1-based numbering).
     */
    public function commit(): void
    {
        $u = Auth::editor();
        $preview = $_SESSION['csv_preview'] ?? null;
        if (!$preview || !hash_equals($preview['nonce'], (string) ($_POST['nonce'] ?? ''))) {
            throw new \InvalidArgumentException('L’aperçu a expiré. Rechargez votre CSV.');
        }
        $mapping = (array) ($_POST['map'] ?? []);
        $records = [];
        foreach ($preview['rows'] as $i => $row) {
            $data = [];
            foreach (
                [
                    'title',
                    'description',
                    'type',
                    'status',
                    'priority',
                    'start_date',
                    'due_date',
                    'estimated_hours',
                    'progress_percent',
                    'labels',
                ]
                as $field
            ) {
                if (!empty($mapping[$field]) && array_key_exists($mapping[$field], $row)) {
                    $data[$field] = $row[$mapping[$field]];
                }
            }
            // CSV files contain display labels ("Bug", "Haute"...): convert them,
            // case-insensitively, to the internal codes of the project's workflow.
            foreach (
                [
                    'type' => \App\Models\Workflow::labels('type',(int)$preview['project_id'],true),
                    'status' => \App\Models\Workflow::labels('status',(int)$preview['project_id'],true),
                    'priority' => Ticket::PRIORITIES,
                ]
                as $field => $options
            ) {
                if (isset($data[$field])) {
                    foreach ($options as $key => $label) {
                        if (mb_strtolower($label) === mb_strtolower($data[$field])) {
                            $data[$field] = $key;
                        }
                    }
                }
            }
            try {
                Ticket::validate($data, (int) $preview['project_id']);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('Ligne ' . ($i + 2) . ' : ' . $e->getMessage());
            }
            $records[] = $data;
        }
        DB::transaction(function () use ($records, $preview, $u) {
            foreach ($records as $record) {
                Ticket::create((int) $preview['project_id'], $record, (int) $u['id']);
            }
        });
        unset($_SESSION['csv_preview']);
        flash(count($records) . ' tickets importés.');
        redirect('/list?project=' . $preview['project_id']);
    }
}
