<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\DataRow;
use JakubBoucek\Hydrator\Tests\Support\Mariadb;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

if (!extension_loaded('mysqli')) {
    Tester\Environment::skip('Requires ext-mysqli.');
}

const TABLE = 'data_row_mysqli';

// PDO helper has the connect-retry, so the server is warm for mysqli
Mariadb::initSchema(Mariadb::pdo(), TABLE);

$params = Mariadb::dsnParams();
$mysqli = new mysqli($params['host'], Mariadb::user(), Mariadb::password(), $params['dbname'], $params['port']);

$hydrator = new Hydrator(DataRow::class, Mysql::class, new DateTimeZone('Europe/Prague'));

test('hydrates a mysqli row (values as strings)', function () use ($mysqli, $hydrator): void {
    $result = $mysqli->query('SELECT * FROM ' . TABLE . ' WHERE id = 1');
    $row = $result === false ? null : $result->fetch_assoc();
    Assert::type('array', $row);

    Assert::same('42', $row['counter']); // sanity: mysqli->query() returns strings
    Mariadb::assertReference($hydrator->fromData($row));
});

test('roundtrip: mysqli write keeps the fraction as well', function () use ($mysqli, $hydrator): void {
    $row = $mysqli->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch_assoc();
    $entity = $hydrator->fromData($row);

    $data = $hydrator->toData($entity);
    unset($data['id']);

    $columns = implode(', ', array_map(fn(string $column): string => "`{$column}`", array_keys($data)));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $statement = $mysqli->prepare('INSERT INTO ' . TABLE . " ({$columns}) VALUES ({$placeholders})");
    $statement->execute(array_values($data));
    $newId = (int) $mysqli->insert_id;

    $raw = $mysqli->query('SELECT measured_at, alarm_at FROM ' . TABLE . " WHERE id = {$newId}")->fetch_assoc();
    Assert::same('2026-07-29 10:30:00.123456', $raw['measured_at']);
    Assert::same('08:30:00.125', $raw['alarm_at']);

    Mariadb::assertReference(
        $hydrator->fromData($mysqli->query('SELECT * FROM ' . TABLE . " WHERE id = {$newId}")->fetch_assoc()),
    );
});
