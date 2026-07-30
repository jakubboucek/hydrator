<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\DataRow;
use JakubBoucek\Hydrator\Tests\Support\Mariadb;
use Nette\Caching\Storages\MemoryStorage;
use Nette\Database\Connection;
use Nette\Database\Conventions\DiscoveredConventions;
use Nette\Database\Explorer;
use Nette\Database\Structure;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

const TABLE = 'data_row_fraction';

$pdo = Mariadb::pdo();
Mariadb::initSchema($pdo, TABLE);

$connection = new Connection(Mariadb::dsn(), Mariadb::user(), Mariadb::password(), [
    'convertBoolean' => true,
    'newDateTime' => true,
]);
$storage = new MemoryStorage();
$structure = new Structure($connection, $storage);
$explorer = new Explorer($connection, $structure, new DiscoveredConventions($structure), $storage);

$hydrator = new Hydrator(DataRow::class, NetteDatabase::class, new DateTimeZone('Europe/Prague'));

test('end to end: #[Fraction] string export keeps microseconds through Nette writes', function () use ($pdo, $explorer, $hydrator): void {
    $entity = $hydrator->fromData($explorer->table(TABLE)->get(1));

    $data = $hydrator->toData($entity);
    unset($data['id']);
    // sanity: the Fraction fields left the hydrator as finished strings
    Assert::same('2026-07-29 10:30:00.123456', $data['measured_at']);
    Assert::same('08:30:00.125', $data['alarm_at']);

    $inserted = $explorer->table(TABLE)->insert($data);
    assert($inserted instanceof Nette\Database\Table\ActiveRow);
    $newId = $inserted->getPrimary();

    // raw proof straight from the database: the fraction survived the write
    $raw = $pdo->query("SELECT measured_at, alarm_at FROM " . TABLE . " WHERE id = {$newId}")
        ->fetch(PDO::FETCH_ASSOC);
    Assert::same('2026-07-29 10:30:00.123456', $raw['measured_at']);
    Assert::same('08:30:00.125', $raw['alarm_at']);
});

test('counter-proof: an instance written by Nette itself loses the microseconds', function () use ($pdo, $explorer): void {
    $row = $explorer->table(TABLE)->get(1);
    assert($row instanceof Nette\Database\Table\ActiveRow);

    $copy = iterator_to_array($row);
    unset($copy['id']);
    // hand Nette the instance directly (what a pass-through export would do)
    $copy['measured_at'] = new DateTimeImmutable('2026-07-29 10:30:00.123456');

    $inserted = $explorer->table(TABLE)->insert($copy);
    assert($inserted instanceof Nette\Database\Table\ActiveRow);

    $raw = $pdo->query('SELECT measured_at FROM ' . TABLE . " WHERE id = {$inserted->getPrimary()}")
        ->fetch(PDO::FETCH_ASSOC);
    Assert::same('2026-07-29 10:30:00.000000', $raw['measured_at']);
});
