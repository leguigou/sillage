<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Auth, DB};
use App\Models\Ticket;
final class AttachmentController
{
    public static function store(int $ticket, array $file): void
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
            DB::run(
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
    }
    public function upload(string $id): void
    {
        Auth::editor();
        Ticket::find((int) $id);
        self::store((int) $id, $_FILES['file'] ?? []);
        flash('Pièce jointe ajoutée.');
        redirect('/tickets/' . (int) $id);
    }
    public function download(string $id): void
    {
        Auth::requireUser();
        $file = DB::one('SELECT * FROM attachments WHERE id=?', [(int) $id]);
        if (!$file) {
            http_response_code(404);
            exit();
        }
        $path = ROOT . '/storage/uploads/' . $file['stored_name'];
        if (!is_file($path)) {
            http_response_code(404);
            exit('Fichier manquant.');
        }
        header('Content-Type: application/octet-stream');
        header(
            "Content-Disposition: attachment; filename=\"piece-jointe\"; filename*=UTF-8''" .
                rawurlencode($file['original_name']),
        );
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }
}
