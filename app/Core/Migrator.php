<?php
declare(strict_types=1);
namespace App\Core;
/**
 * Forward-only schema migrations, run by `php bin/console.php migrate` on every deployment.
 * Each database/*.sql file is applied once, in file-name order (hence the numeric prefixes), and
 * recorded by file name in the migrations table. There are no down migrations. Files must never be
 * renamed or edited after release: a renamed file would run again, an edited one never would.
 */
final class Migrator
{
    /** Apply pending migrations and return the file names applied by this run. */
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
            // The whole file is sent as one multi-statement batch, outside any transaction (MySQL DDL
            // commits implicitly): statements before a failing one stay applied.
            DB::connection()->exec(file_get_contents($path));
            DB::run('INSERT INTO migrations(version) VALUES(?)', [$version]);
            $applied[] = $version;
        }
        return $applied;
    }
}
