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

const TABLE = 'data_row_nette';

Mariadb::initSchema(Mariadb::freshDatabase('nette'), TABLE);

$hydrator = new Hydrator(DataRow::class, NetteDatabase::class, new DateTimeZone('Europe/Prague'));

function explorer(bool $convertBoolean, bool $newDateTime): Explorer
{
    $connection = new Connection(Mariadb::dsnFor('nette'), Mariadb::user(), Mariadb::password(), [
        'convertBoolean' => $convertBoolean,
        'newDateTime' => $newDateTime,
    ]);
    $storage = new MemoryStorage();
    $structure = new Structure($connection, $storage);

    return new Explorer($connection, $structure, new DiscoveredConventions($structure), $storage);
}

// every configuration of the value-converting options must hydrate identically:
// convertBoolean=false delivers int 1/0, newDateTime=false delivers mutable
// Nette\Utils\DateTime instances — the format normalizes both
foreach ([true, false] as $convertBoolean) {
    foreach ([true, false] as $newDateTime) {
        test(
            sprintf('ActiveRow hydration [convertBoolean=%d, newDateTime=%d]', $convertBoolean, $newDateTime),
            function () use ($convertBoolean, $newDateTime, $hydrator): void {
                $row = explorer($convertBoolean, $newDateTime)->table(TABLE)->get(1);

                Mariadb::assertReference($hydrator->fromData($row));
            },
        );
    }
}

test('Row from a direct SQL query hydrates as well', function () use ($hydrator): void {
    $row = explorer(true, true)->query('SELECT * FROM ' . TABLE . ' WHERE id = ?', 1)->fetch();

    Mariadb::assertReference($hydrator->fromData($row));
});

test('roundtrip: extracted data INSERTs back through Explorer', function () use ($hydrator): void {
    $explorer = explorer(true, true);
    $entity = $hydrator->fromData($explorer->table(TABLE)->get(1));

    $data = $hydrator->toData($entity);
    unset($data['id']);

    $inserted = $explorer->table(TABLE)->insert($data);
    assert($inserted instanceof Nette\Database\Table\ActiveRow);

    $reloaded = $hydrator->fromData($explorer->table(TABLE)->get($inserted->getPrimary()));
    Mariadb::assertReference($reloaded);
});

test('fromDataSet streams the whole Selection keyed by the primary key', function () use ($hydrator): void {
    $entities = iterator_to_array($hydrator->fromDataSet(explorer(true, true)->table(TABLE)));

    Assert::same([1, 2], array_keys($entities));
    Mariadb::assertReference($entities[1]);
});
