<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\DynamicStruct;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\Parcel;
use JakubBoucek\Hydrator\Tests\Support\Mariadb;
use Nette\Caching\Storages\MemoryStorage;
use Nette\Database\Connection;
use Nette\Database\Conventions\DiscoveredConventions;
use Nette\Database\Explorer;
use Nette\Database\Structure;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

const TABLE = 'parcel';

$pdo = Mariadb::freshDatabase('struct');
$pdo->exec('DROP TABLE IF EXISTS ' . TABLE);
$pdo->exec('CREATE TABLE ' . TABLE . ' (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    contact JSON NULL,
    tags JSON NULL,
    notes JSON NULL,
    meta JSON NULL
) ENGINE=InnoDB CHARSET=utf8mb4');
$pdo->exec('INSERT INTO ' . TABLE . " (label, contact, tags, notes, meta) VALUES
    ('full', '{\"email\":\"ada@example.com\"}', '[\"vip\",\"legacy\"]',
     '[{\"text\":\"Zaplaceno\",\"author\":\"admin\",\"date\":\"2026-07-30 10:00:00\"}]', '{\"source\":\"import\",\"batch\":7}'),
    ('empty', NULL, NULL, NULL, NULL)");

$connection = new Connection(Mariadb::dsnFor('struct'), Mariadb::user(), Mariadb::password(), [
    'convertBoolean' => true,
    'newDateTime' => true,
]);
$storage = new MemoryStorage();
$structure = new Structure($connection, $storage);
$explorer = new Explorer($connection, $structure, new DiscoveredConventions($structure), $storage);

$hydrator = new Hydrator(Parcel::class, NetteDatabase::class, new DateTimeZone('Europe/Prague'));

test('JSON columns hydrate into structs (Nette and PDO paths alike)', function () use ($pdo, $explorer, $hydrator): void {
    foreach ([
        $explorer->table(TABLE)->get(1),
        $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC),
    ] as $row) {
        $parcel = $hydrator->fromData($row);

        Assert::same('ada@example.com', $parcel->contact->email);
        Assert::true($parcel->tags->has('vip'));
        Assert::same('2026-07-30 10:00:00 (admin): Zaplaceno', $parcel->notes->toText());
        Assert::same('import', $parcel->meta->source);
        Assert::same(7, $parcel->meta->batch);
    }
});

test('NULL columns hydrate into existing, writable empty structs', function () use ($explorer, $hydrator): void {
    $parcel = $hydrator->fromData($explorer->table(TABLE)->get(2));

    Assert::true($parcel->tags->isEmpty());
    Assert::true($parcel->notes->isEmpty());
    $parcel->tags->add('late'); // writable without any null-checks
    Assert::same('late', $parcel->tags->toText());
});

test('roundtrip through Explorer: values keep JSON, empty structs land as raw NULL', function () use ($pdo, $explorer, $hydrator): void {
    $full = $hydrator->fromData($explorer->table(TABLE)->get(1));
    $empty = $hydrator->fromData($explorer->table(TABLE)->get(2));

    $fullData = $hydrator->toData($full);
    $emptyData = $hydrator->toData($empty);
    unset($fullData['id'], $emptyData['id']);

    $fullRow = $explorer->table(TABLE)->insert($fullData);
    $emptyRow = $explorer->table(TABLE)->insert($emptyData);
    assert($fullRow instanceof Nette\Database\Table\ActiveRow && $emptyRow instanceof Nette\Database\Table\ActiveRow);

    // raw proof straight from the database
    $raw = $pdo->query('SELECT * FROM ' . TABLE . " WHERE id = {$fullRow->getPrimary()}")->fetch(PDO::FETCH_ASSOC);
    Assert::same('{"email":"ada@example.com"}', $raw['contact']);
    Assert::same('["vip","legacy"]', $raw['tags']);

    $rawEmpty = $pdo->query('SELECT * FROM ' . TABLE . " WHERE id = {$emptyRow->getPrimary()}")->fetch(PDO::FETCH_ASSOC);
    Assert::null($rawEmpty['contact']); // NULL, never '{}'
    Assert::null($rawEmpty['tags']);    // NULL, never '[]'
    Assert::null($rawEmpty['notes']);
    Assert::null($rawEmpty['meta']);

    // and the reloaded copy matches the original
    $reloaded = $hydrator->fromData($explorer->table(TABLE)->get($fullRow->getPrimary()));
    Assert::equal($full->notes->toArray(), $reloaded->notes->toArray());
});

test('DynamicStruct keeps unknown keys through the whole DB roundtrip', function () use ($explorer, $hydrator): void {
    $parcel = $hydrator->fromData($explorer->table(TABLE)->get(1));
    Assert::type(DynamicStruct::class, $parcel->meta);

    $data = $hydrator->toData($parcel);
    Assert::same('{"source":"import","batch":7}', $data['meta']);
});
