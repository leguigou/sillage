<?php
declare(strict_types=1);
namespace App\Services;
final class Csv
{
    public static function safe(mixed $value): string
    {
        $s = (string) ($value ?? '');
        return preg_match('/^[\s]*[=+@\-]/u', $s) ? "'" . $s : $s;
    }
    public static function parse(string $path): array
    {
        if (!is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            throw new \InvalidArgumentException('Le CSV doit peser au maximum 2 Mo.');
        }
        $f = fopen($path, 'rb');
        $first = fgets($f);
        if ($first === false) {
            throw new \InvalidArgumentException('Le fichier est vide.');
        }
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($f);
        $headers = fgetcsv($f, 0, $delimiter, '"', '');
        if (!$headers) {
            throw new \InvalidArgumentException('En-têtes CSV manquants.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        $headers = array_map(fn($v) => mb_strtolower(trim($v)), $headers);
        if (count($headers) !== count(array_unique($headers))) {
            throw new \InvalidArgumentException('Les en-têtes ne doivent pas être dupliqués.');
        }
        $rows = [];
        while (($row = fgetcsv($f, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            if (count($rows) >= 500) {
                throw new \InvalidArgumentException('Import limité à 500 tickets par fichier.');
            }
            if (count($row) !== count($headers)) {
                throw new \InvalidArgumentException(
                    'Colonnes incohérentes à la ligne ' . (count($rows) + 2) . '.',
                );
            }
            if (!mb_check_encoding(implode('', $row), 'UTF-8')) {
                throw new \InvalidArgumentException('Enregistrez le CSV au format UTF-8.');
            }
            $rows[] = array_combine($headers, $row);
        }
        fclose($f);
        if (!$rows) {
            throw new \InvalidArgumentException('Le fichier ne contient aucun ticket.');
        }
        return ['headers' => $headers, 'rows' => $rows];
    }
}
