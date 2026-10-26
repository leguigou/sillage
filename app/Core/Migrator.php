<?php
declare(strict_types=1);
namespace App\Core;
final class Migrator
{
    public static function run(): array
    {
        DB::connection()->exec(
            'CREATE TABLE IF NOT EXISTS migrations(version VARCHAR(100) PRIMARY KEY,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB',
        );
        $applied = [];
        foreach (glob(ROOT . '/database/*.sql') as $path) {
            $version = basename($path);
            if (DB::one('SELECT version FROM migrations WHERE version=?', [$version])) {
                continue;
            }
            DB::connection()->exec(file_get_contents($path));
            DB::run('INSERT INTO migrations(version) VALUES(?)', [$version]);
            $applied[] = $version;
        }
        return $applied;
    }
}
