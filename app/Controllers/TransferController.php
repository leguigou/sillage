<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB, View};
use App\Models\{Project, Ticket};
use App\Services\{Csv, Query};
final class TransferController
{
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
                    'start_date',
                    'due_date',
                    'estimated_hours',
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
                    'start_date' => $t['start_date'],
                    'due_date' => $t['due_date'],
                    'estimated_hours' => $t['estimated_hours'],
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
                    'labels',
                ]
                as $field
            ) {
                if (!empty($mapping[$field]) && array_key_exists($mapping[$field], $row)) {
                    $data[$field] = $row[$mapping[$field]];
                }
            }
            foreach (
                [
                    'type' => Ticket::TYPES,
                    'status' => Ticket::STATUSES,
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
