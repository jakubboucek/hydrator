<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Support;

use JakubBoucek\Hydrator\Tests\Fixtures\ArticleStatus;
use JakubBoucek\Hydrator\Tests\Fixtures\DataRow;
use PDO;
use PDOException;
use Tester\Assert;
use Tester\Environment;

/**
 * Shared plumbing of the MariaDB integration tests. The tests run only when
 * the DATABASE_DSN environment variable is set (see CLAUDE.md), otherwise
 * they skip — the unit test run stays server-independent.
 */
final class Mariadb
{
    public static function dsn(): string
    {
        $dsn = getenv('DATABASE_DSN');
        if ($dsn === false || $dsn === '') {
            Environment::skip('Requires a MariaDB server, set DATABASE_DSN to run.');
        }

        return $dsn;
    }

    /**
     * Creates a fresh, per-test-file database and returns a PDO connected to
     * it. Isolation matters: Nette Structure enumerates every table of a
     * database on load, so parallel test files sharing one database race
     * each other's DROP/CREATE.
     */
    public static function freshDatabase(string $suffix): PDO
    {
        $name = self::databaseName($suffix);
        $server = self::pdo();
        $server->exec("DROP DATABASE IF EXISTS `{$name}`");
        $server->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4");

        $pdo = new PDO(self::dsnFor($suffix), self::user(), self::password(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return $pdo;
    }

    /**
     * An additional connection to an existing per-test-file database
     * (e.g. with different PDO options).
     *
     * @param array<int, mixed> $options
     */
    public static function pdoFor(string $suffix, array $options = []): PDO
    {
        return new PDO(self::dsnFor($suffix), self::user(), self::password(), $options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * The DSN pointing to the per-test-file database.
     */
    public static function dsnFor(string $suffix): string
    {
        $name = self::databaseName($suffix);
        $dsn = (string) preg_replace('~dbname=[^;]*~', "dbname={$name}", self::dsn());

        return str_contains($dsn, 'dbname=') ? $dsn : "{$dsn};dbname={$name}";
    }

    public static function databaseName(string $suffix): string
    {
        return "hydrator_test_{$suffix}";
    }

    /**
     * @return array{host: string, port: int, dbname: string}
     */
    public static function dsnParams(?string $dsn = null): array
    {
        $params = ['host' => 'localhost', 'port' => 3306, 'dbname' => ''];
        foreach (explode(';', substr($dsn ?? self::dsn(), strlen('mysql:'))) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            if ($key === 'port') {
                $params['port'] = (int) $value;
            } elseif ($key === 'host' || $key === 'dbname') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    public static function user(): string
    {
        return getenv('DATABASE_USER') ?: 'root';
    }

    public static function password(): string
    {
        return getenv('DATABASE_PASSWORD') ?: 'devstack';
    }

    /**
     * @param array<int, mixed> $options
     */
    public static function pdo(array $options = []): PDO
    {
        $dsn = self::dsn();

        // the server may still be starting (fresh container, CI service)
        $attempts = 20;
        while (true) {
            try {
                return new PDO($dsn, self::user(), self::password(), $options + [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
            } catch (PDOException $e) {
                if (--$attempts <= 0) {
                    throw $e;
                }
                sleep(1);
            }
        }
    }

    /**
     * Creates the common-column-types table (a fresh one per test file —
     * Tester runs test files in parallel) and seeds one reference row.
     */
    public static function initSchema(PDO $pdo, string $table): void
    {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        $pdo->exec(<<<SQL
            CREATE TABLE `{$table}` (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                active TINYINT(1) NOT NULL,
                counter INT NOT NULL,
                big_number BIGINT NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                ratio DOUBLE NOT NULL,
                title VARCHAR(100) NOT NULL,
                body TEXT NULL,
                born_on DATE NOT NULL,
                created_at DATETIME NOT NULL,
                measured_at DATETIME(6) NOT NULL,
                synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                elapsed TIME NOT NULL,
                alarm_at TIME(3) NOT NULL,
                status ENUM('draft', 'published') NOT NULL,
                payload JSON NULL
            ) ENGINE=InnoDB CHARSET=utf8mb4
            SQL);
        $pdo->exec(<<<SQL
            INSERT INTO `{$table}`
                (active, counter, big_number, price, ratio, title, body, born_on, created_at,
                 measured_at, synced_at, elapsed, alarm_at, status, payload)
            VALUES
                (1, 42, 9223372036854775807, 149.90, 0.5, 'Reference', NULL, '1985-07-01',
                 '2026-07-29 10:30:00', '2026-07-29 10:30:00.123456', '2026-07-29 11:00:00',
                 '123:45:06', '08:30:00.125', 'published', '{"a": 1}')
            SQL);
    }

    /**
     * Typed expectations of the seeded reference row — shared by every
     * access path (PDO, mysqli, nette/database in all configurations).
     */
    public static function assertReference(DataRow $row): void
    {
        Assert::true($row->active);
        Assert::same(42, $row->counter);
        Assert::same(9223372036854775807, $row->bigNumber);
        Assert::same(149.9, $row->price);
        Assert::same(0.5, $row->ratio);
        Assert::same('Reference', $row->title);
        Assert::null($row->body);
        Assert::same('1985-07-01 00:00:00', $row->bornOn->format('Y-m-d H:i:s'));
        Assert::same('2026-07-29 10:30:00', $row->createdAt->format('Y-m-d H:i:s'));
        Assert::same('Europe/Prague', $row->createdAt->getTimezone()->getName());
        Assert::same('2026-07-29 10:30:00.123456', $row->measuredAt->format('Y-m-d H:i:s.u'));
        Assert::same('2026-07-29 11:00:00', $row->syncedAt->format('Y-m-d H:i:s'));
        Assert::same(0, $row->elapsed->invert);
        Assert::same(123, $row->elapsed->h);
        Assert::same(45, $row->elapsed->i);
        Assert::same(6, $row->elapsed->s);
        Assert::same('0001-01-01 08:30:00.125000', $row->alarmAt->format('Y-m-d H:i:s.u'));
        Assert::same(ArticleStatus::Published, $row->status);
        Assert::same('{"a": 1}', $row->payload);
    }
}
