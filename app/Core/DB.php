<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
final class DB
{
    private static ?PDO $connection = null;
    public static function connection(): PDO
    {
        if (!self::$connection) {
            self::$connection = new PDO(
                'mysql:host=' .
                    env('DB_HOST', '127.0.0.1') .
                    ';port=' .
                    env('DB_PORT', '3306') .
                    ';dbname=' .
                    env('DB_DATABASE', 'sillage') .
                    ';charset=utf8mb4',
                env('DB_USERNAME', 'sillage'),
                env('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
            self::$connection->exec("SET time_zone = '+00:00'");
        }
        return self::$connection;
    }
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $s = self::connection()->prepare($sql);
        $s->execute($params);
        return $s;
    }
    public static function one(string $sql, array $params = []): ?array
    {
        return self::run($sql, $params)->fetch() ?: null;
    }
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::connection()->lastInsertId();
    }
    public static function transaction(callable $fn): mixed
    {
        $db = self::connection();
        if ($db->inTransaction()) {
            return $fn();
        }
        $db->beginTransaction();
        try {
            $result = $fn();
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
