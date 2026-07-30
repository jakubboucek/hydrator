<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\EdgeCaseLossy;
use JakubBoucek\Hydrator\Tests\Fixtures\EdgeCaseSafe;
use JakubBoucek\Hydrator\Tests\Fixtures\EdgeCaseStrict;
use JakubBoucek\Hydrator\Tests\Support\Mariadb;
use Nette\Caching\Storages\MemoryStorage;
use Nette\Database\Connection;
use Nette\Database\Conventions\DiscoveredConventions;
use Nette\Database\Explorer;
use Nette\Database\Structure;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Fixtures/EdgeCase.php';

// Legacy databases hold values a modern schema would refuse — these tests
// document how the hydrator behaves on them. Not every combination is meant
// to be supported; what matters is that the behavior is known and failures
// are loud, not silent.

const TABLE = 'edge_case';

$prague = new DateTimeZone('Europe/Prague');

$pdo = Mariadb::pdo();
$pdo->exec('DROP TABLE IF EXISTS ' . TABLE);
$pdo->exec('CREATE TABLE ' . TABLE . ' (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    zero_date DATE NULL,
    zero_stamp DATETIME NULL,
    big_unsigned BIGINT UNSIGNED NOT NULL,
    exact_price DECIMAL(20,4) NOT NULL
) ENGINE=InnoDB CHARSET=utf8mb4');
$pdo->exec("SET SESSION sql_mode = ''");
$pdo->exec('INSERT INTO ' . TABLE . " (zero_date, zero_stamp, big_unsigned, exact_price)
    VALUES ('0000-00-00', '0000-00-00 00:00:00', 18446744073709551615, 12345678901234.5678)");

$explorer = (function (): Explorer {
    $connection = new Connection(Mariadb::dsn(), Mariadb::user(), Mariadb::password(), [
        'convertBoolean' => true,
        'newDateTime' => true,
    ]);
    $storage = new MemoryStorage();
    $structure = new Structure($connection, $storage);

    return new Explorer($connection, $structure, new DiscoveredConventions($structure), $storage);
})();

test('nette/database converts zero dates to null — nullable properties handle them', function () use ($explorer, $prague): void {
    $hydrator = new Hydrator(EdgeCaseLossy::class, NetteDatabase::class, $prague);
    $row = $explorer->table(TABLE)->get(1);

    $entity = $hydrator->fromData($row);

    Assert::null($entity->zeroDate);
    Assert::null($entity->zeroStamp);
});

test('a non-nullable property over a zero date fails loudly, not silently', function () use ($explorer, $prague): void {
    $hydrator = new Hydrator(EdgeCaseStrict::class, NetteDatabase::class, $prague);

    Assert::exception(
        fn() => $hydrator->fromData($explorer->table(TABLE)->get(1)),
        HydrationException::class,
        "~Field 'zero_stamp' is null but property .*::\\\$zeroStamp is not nullable~",
    );
});

test('KNOWN TRAP: the Mysql format parses raw zero-date strings into a nonsense PHP date', function () use ($pdo, $prague): void {
    $hydrator = new Hydrator(EdgeCaseLossy::class, Mysql::class, $prague);
    $row = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    $entity = $hydrator->fromData($row);

    // documented current behavior: PHP rolls '0000-00-00' over to -0001-11-30
    // (nette/database shields its users by converting to null; raw PDO users
    // must treat zero dates themselves — or map the column as ?string)
    Assert::same('-0001-11-30', $entity->zeroDate->format('Y-m-d'));
    Assert::same('-0001-11-30 00:00:00', $entity->zeroStamp->format('Y-m-d H:i:s'));
});

test('KNOWN TRAP: unsigned BIGINT beyond PHP_INT_MAX saturates in an int property', function () use ($pdo, $explorer, $prague): void {
    // both access paths deliver the out-of-range value as a string,
    // the int cast then silently saturates at PHP_INT_MAX
    $mysql = new Hydrator(EdgeCaseLossy::class, Mysql::class, $prague);
    $row = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    Assert::same(PHP_INT_MAX, $mysql->fromData($row)->bigUnsigned);

    $nette = new Hydrator(EdgeCaseLossy::class, NetteDatabase::class, $prague);
    Assert::same(PHP_INT_MAX, $nette->fromData($explorer->table(TABLE)->get(1))->bigUnsigned);
});

test('KNOWN LIMIT: DECIMAL(20,4) loses precision in a float property', function () use ($pdo, $prague): void {
    $hydrator = new Hydrator(EdgeCaseLossy::class, Mysql::class, $prague);
    $row = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    Assert::same(12345678901234.568, $hydrator->fromData($row)->exactPrice);
});

test('safe legacy mappings: string properties keep every value exact (Mysql format)', function () use ($pdo, $prague): void {
    $hydrator = new Hydrator(EdgeCaseSafe::class, Mysql::class, $prague);
    $row = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    $entity = $hydrator->fromData($row);

    Assert::same('0000-00-00', $entity->zeroDate);
    Assert::same('0000-00-00 00:00:00', $entity->zeroStamp);
    Assert::same('18446744073709551615', $entity->bigUnsigned);
    Assert::same('12345678901234.5678', $entity->exactPrice);
});
