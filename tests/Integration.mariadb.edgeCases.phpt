<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\EdgeCaseLossy;
use JakubBoucek\Hydrator\Tests\Fixtures\EdgeCaseNumbers;
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

$pdo = Mariadb::freshDatabase('edge');
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
    $connection = new Connection(Mariadb::dsnFor('edge'), Mariadb::user(), Mariadb::password(), [
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

test('raw zero-date strings hydrate as null with a warning (parity with Nette)', function () use ($pdo, $prague): void {
    $hydrator = new Hydrator(EdgeCaseLossy::class, Mysql::class, $prague);
    $row = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    $entity = null;
    Assert::error(function () use (&$entity, $hydrator, $row): void {
        $entity = $hydrator->fromData($row);
    }, [
        [E_USER_WARNING, "~Zero date '0000-00-00' in field 'zero_date'~"],
        [E_USER_WARNING, "~Zero date '0000-00-00 00:00:00' in field 'zero_stamp'~"],
    ]);

    Assert::null($entity->zeroDate);
    Assert::null($entity->zeroStamp);
});

test('a non-nullable property over a raw zero date warns and fails loudly', function () use ($pdo, $prague): void {
    $hydrator = new Hydrator(EdgeCaseStrict::class, Mysql::class, $prague);
    $row = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    Assert::error(function () use ($hydrator, $row): void {
        Assert::exception(
            fn() => $hydrator->fromData($row),
            HydrationException::class,
            "~Field 'zero_stamp' is null but property .*::\\\$zeroStamp is not nullable~",
        );
    }, E_USER_WARNING, '~Zero date~');
});

test('KNOWN TRAP: unsigned BIGINT beyond PHP_INT_MAX saturates in an int property', function () use ($pdo, $explorer, $prague): void {
    // both access paths deliver the out-of-range value as a string,
    // the int cast then silently saturates at PHP_INT_MAX
    $mysql = new Hydrator(EdgeCaseNumbers::class, Mysql::class, $prague);
    $row = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    Assert::same(PHP_INT_MAX, $mysql->fromData($row)->bigUnsigned);

    $nette = new Hydrator(EdgeCaseNumbers::class, NetteDatabase::class, $prague);
    Assert::same(PHP_INT_MAX, $nette->fromData($explorer->table(TABLE)->get(1))->bigUnsigned);
});

test('KNOWN LIMIT: DECIMAL(20,4) loses precision in a float property', function () use ($pdo, $prague): void {
    $hydrator = new Hydrator(EdgeCaseNumbers::class, Mysql::class, $prague);
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
