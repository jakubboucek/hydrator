<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\DataRow;
use JakubBoucek\Hydrator\Tests\Support\Mariadb;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

const TABLE = 'data_row_pdo';

$pdo = Mariadb::freshDatabase('pdo');
Mariadb::initSchema($pdo, TABLE);

$hydrator = new Hydrator(DataRow::class, Mysql::class, new DateTimeZone('Europe/Prague'));

test('hydrates a PDO row in the default mode (native int/float since PHP 8.1)', function () use ($pdo, $hydrator): void {
    $row = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    Assert::same(42, $row['counter']); // sanity: really native types
    Mariadb::assertReference($hydrator->fromData($row));
});

test('hydrates a PDO row in the legacy stringified mode', function () use ($hydrator): void {
    $stringified = Mariadb::pdo([PDO::ATTR_STRINGIFY_FETCHES => true]);
    $row = $stringified->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    Assert::same('42', $row['counter']); // sanity: really strings
    Mariadb::assertReference($hydrator->fromData($row));
});

test('roundtrip: extracted data INSERTs back and reads identically', function () use ($pdo, $hydrator): void {
    $entity = $hydrator->fromData($pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC));

    $data = $hydrator->toData($entity);
    unset($data['id']);

    $columns = implode(', ', array_map(fn(string $column): string => "`{$column}`", array_keys($data)));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $pdo->prepare('INSERT INTO ' . TABLE . " ({$columns}) VALUES ({$placeholders})")
        ->execute(array_values($data));
    $newId = (int) $pdo->lastInsertId();

    $reloaded = $hydrator->fromData(
        $pdo->query('SELECT * FROM ' . TABLE . " WHERE id = {$newId}")->fetch(PDO::FETCH_ASSOC),
    );
    Mariadb::assertReference($reloaded);

    $reloadedData = $hydrator->toData($reloaded);
    unset($reloadedData['id']);
    Assert::equal($data, $reloadedData);
});
