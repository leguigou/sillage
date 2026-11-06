<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Validates an uploaded image (company logo in CompanyController, user avatar in WorkspaceController)
 * and returns its bytes, MIME type and SHA-256 digest. The type is detected from the file content
 * (finfo + getimagesize must agree), never from the client-supplied name or MIME type.
 * Limits: PNG, JPEG or WebP, at most 2 MB and 4096 x 4096 pixels.
 */
final class ImageUpload
{
    /** Validates a $_FILES entry; returns null when no file was sent. */
    public static function fromUpload(array $file, string $label = 'L’image'): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new \InvalidArgumentException($label . ' n’a pas pu être chargé.');
        }
        return self::validate($file['tmp_name'], $label);
    }

    public static function validate(string $path, string $label = 'L’image'): array
    {
        $size = filesize($path);
        if (!$size || $size > 2 * 1024 * 1024) throw new \InvalidArgumentException($label . ' doit peser au maximum 2 Mo.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/png','image/jpeg','image/webp'], true)) {
            throw new \InvalidArgumentException('Utilisez une image PNG, JPEG ou WebP.');
        }
        $dimensions = @getimagesize($path);
        if (!$dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096 || ($dimensions['mime'] ?? '') !== $mime) {
            throw new \InvalidArgumentException('Image invalide ou trop grande (4 096 × 4 096 pixels maximum).');
        }
        $data = file_get_contents($path);
        return ['data'=>$data, 'mime'=>$mime, 'digest'=>hash('sha256',$data)];
    }
}
