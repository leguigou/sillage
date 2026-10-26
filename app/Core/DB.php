<?php
declare(strict_types=1);
namespace App\Core;
use PDO;
final class DB
{
    private static ?PDO $connection = null;
    public static function sslCA(): string
    {
        $path = env('DB_SSL_CA');
        if ($path !== '' && !is_readable($path)) {
            throw new \RuntimeException('Le certificat DB_SSL_CA est introuvable ou illisible.');
        }
        return $path;
    }
    public static function connection(): PDO
    {
        if (!self::$connection) {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 10,
            ];
            $sslCA = self::sslCA();
            if ($sslCA !== '') {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCA;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
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
                $options,
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
