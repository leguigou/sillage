<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB};
use App\Models\Ticket;
final class AttachmentController
{
    public static function store(int $ticket, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Le fichier n’a pas pu être chargé.');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException('Fichier invalide.');
        }
        $size = filesize($file['tmp_name']);
        if (!$size || $size > (int) env('UPLOAD_MAX_MB', '10') * 1024 * 1024) {
            throw new \InvalidArgumentException('Le fichier dépasse la taille autorisée.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (
            !in_array(
                $mime,
                [
                    'image/png',
                    'image/jpeg',
                    'image/webp',
                    'application/pdf',
                    'text/plain',
                    'text/csv',
                    'application/zip',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Type de fichier non autorisé. Utilisez une image, PDF, texte, CSV, ZIP ou document Office.',
            );
        }
        $dir = ROOT . '/storage/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $name = bin2hex(random_bytes(32));
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            throw new \RuntimeException('Stockage du fichier impossible.');
        }
        try {
            $id = DB::insert(
                'INSERT INTO attachments(ticket_id,original_name,stored_name,mime,size_bytes) VALUES(?,?,?,?,?)',
                [
                    $ticket,
                    mb_substr(basename(str_replace('\\', '/', $file['name'])), 0, 240),
                    $name,
                    $mime,
                    $size,
                ],
            );
        } catch (\Throwable $e) {
            unlink($dir . '/' . $name);
            throw $e;
        }
        return DB::one('SELECT id,ticket_id,original_name,mime,size_bytes FROM attachments WHERE id=?', [$id]);
    }
    public function upload(string $id): void
    {
        Auth::editor();
        Ticket::find((int) $id);
        $files = [];
        if (isset($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
            if (count($_FILES['files']['name']) > 20) {
                throw new \InvalidArgumentException('20 fichiers maximum par envoi.');
            }
            foreach (array_keys($_FILES['files']['name']) as $i) {
                $file = [];
                foreach (['name','tmp_name','size','error','type'] as $key) {
                    $file[$key] = $_FILES['files'][$key][$i] ?? null;
                }
                $files[] = $file;
            }
        } else {
            $files[] = $_FILES['file'] ?? [];
        }
        $stored = [];
        $errors = [];
        foreach ($files as $index => $file) {
            try {
                $saved = self::store((int) $id, $file);
                $saved['index'] = $index;
                $stored[] = $saved;
            } catch (\InvalidArgumentException $error) {
                $errors[] = ['index' => $index, 'message' => $error->getMessage()];
            }
        }
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_response(['files' => $stored, 'errors' => $errors], $stored ? 200 : 422);
        }
        flash($errors ? implode(' ', array_column($errors, 'message')) : 'Pièces jointes ajoutées.', $errors ? 'error' : 'success');
        redirect('/tickets/' . (int) $id);
    }
    public function delete(string $id): void
    {
        $user = Auth::editor();
        $ticket = DB::transaction(function () use ($id, $user) {
            $file = DB::one('SELECT * FROM attachments WHERE id=? FOR UPDATE', [(int) $id]);
            if (!$file) {
                throw new \InvalidArgumentException('Pièce jointe introuvable.');
            }
            $ticket = Ticket::find((int) $file['ticket_id']);
            $path = self::path($file);
            if (is_file($path) && !unlink($path)) {
                throw new \RuntimeException('Suppression du fichier impossible.');
            }
            DB::run('DELETE FROM attachments WHERE id=?', [(int) $id]);
            Ticket::activity((int) $ticket['project_id'], (int) $ticket['id'], (int) $user['id'], 'a supprimé une pièce jointe');
            return (int) $ticket['id'];
        });
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            json_response(['ok' => true]);
        }
        flash('Pièce jointe supprimée.');
        redirect('/tickets/' . $ticket . '#attachments');
    }
    private static function path(array $file): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $file['stored_name'])) {
            throw new \RuntimeException('Identité du fichier invalide.');
        }
        return ROOT . '/storage/uploads/' . $file['stored_name'];
    }
    public function preview(string $id): void
    {
        $this->download($id, true);
    }
    public function download(string $id, bool $preview = false): void
    {
        Auth::requireUser();
        $file = DB::one('SELECT * FROM attachments WHERE id=?', [(int) $id]);
        if (!$file) {
            http_response_code(404);
            exit();
        }
        if ($preview && !in_array($file['mime'], ['image/png','image/jpeg','image/webp'], true)) {
            http_response_code(415);
            exit();
        }
        $path = self::path($file);
        if (!is_file($path)) {
            http_response_code(404);
            exit('Fichier manquant.');
        }
        header('Content-Type: ' . ($preview ? $file['mime'] : 'application/octet-stream'));
        header(
            'Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . "; filename=\"piece-jointe\"; filename*=UTF-8''" .
                rawurlencode($file['original_name']),
        );
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }
}
