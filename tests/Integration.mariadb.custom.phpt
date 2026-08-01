<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\PlainDateAdapter;
use JakubBoucek\Hydrator\Tests\Fixtures\Task;
use JakubBoucek\Hydrator\Tests\Fixtures\UlidAdapter;
use JakubBoucek\Hydrator\Tests\Fixtures\Wallet;
use JakubBoucek\Hydrator\Tests\Support\Mariadb;
use Nette\Caching\Storages\MemoryStorage;
use Nette\Database\Connection;
use Nette\Database\Conventions\DiscoveredConventions;
use Nette\Database\Explorer;
use Nette\Database\Structure;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

const TABLE = 'wallet_case';
const TASK_TABLE = 'task_case';

$prague = new DateTimeZone('Europe/Prague');

$pdo = Mariadb::freshDatabase('custom');
$pdo->exec('DROP TABLE IF EXISTS ' . TABLE);
$pdo->exec('CREATE TABLE ' . TABLE . ' (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    balance INT NOT NULL,
    bonus INT NULL,
    public_id CHAR(26) NOT NULL,
    token VARCHAR(64) NULL,
    active TINYINT(1) NOT NULL
) ENGINE=InnoDB CHARSET=utf8mb4');
$pdo->exec('INSERT INTO ' . TABLE . " (balance, bonus, public_id, token, active)
    VALUES (1990, NULL, '01arz3ndektsv4rrffq69g5fav', 'tok_secret', 1)");

$pdo->exec('DROP TABLE IF EXISTS ' . TASK_TABLE);
$pdo->exec('CREATE TABLE ' . TASK_TABLE . ' (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    due_at DATETIME NOT NULL,
    estimate TIME NOT NULL,
    published_on DATE NOT NULL
) ENGINE=InnoDB CHARSET=utf8mb4');
$pdo->exec('INSERT INTO ' . TASK_TABLE . " (due_at, estimate, published_on)
    VALUES ('2026-08-01 12:00:00', '90:30:00', '2026-08-01')");

$explorer = (function (): Explorer {
    $connection = new Connection(Mariadb::dsnFor('custom'), Mariadb::user(), Mariadb::password(), [
        'convertBoolean' => true,
        'newDateTime' => true,
    ]);
    $storage = new MemoryStorage();
    $structure = new Structure($connection, $storage);

    return new Explorer($connection, $structure, new DiscoveredConventions($structure), $storage);
})();

test('custom types over real columns: PDO path', function () use ($pdo, $prague): void {
    $hydrator = new Hydrator(Wallet::class, Mysql::class, $prague, adapters: [UlidAdapter::class]);

    $wallet = $hydrator->fromData($pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = 1')->fetch(PDO::FETCH_ASSOC));

    Assert::same(1990, $wallet->balance->cents);
    Assert::null($wallet->bonus);
    Assert::same('01arz3ndektsv4rrffq69g5fav', $wallet->publicId->toString());
    Assert::true($wallet->active->on);

    $data = $hydrator->toData($wallet);
    unset($data['id']);
    $columns = implode(', ', array_map(fn(string $c): string => "`{$c}`", array_keys($data)));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $pdo->prepare('INSERT INTO ' . TABLE . " ({$columns}) VALUES ({$placeholders})")
        ->execute(array_values($data));

    $raw = $pdo->query('SELECT * FROM ' . TABLE . ' WHERE id = ' . (int) $pdo->lastInsertId())
        ->fetch(PDO::FETCH_ASSOC);
    Assert::same('1990', (string) $raw['balance']);
    Assert::same('1', (string) $raw['active']);
});

test('custom types over real columns: Nette path with inner-null raw proof', function () use ($pdo, $explorer, $prague): void {
    $hydrator = new Hydrator(Wallet::class, NetteDatabase::class, $prague, adapters: [UlidAdapter::class]);

    $wallet = $hydrator->fromData($explorer->table(TABLE)->get(1));
    Assert::same('19.90', $wallet->balance->format());
    Assert::true($wallet->active->on);

    // inner nullness lands as raw NULL in the database
    $wallet->token->secret = null;
    $data = $hydrator->toData($wallet);
    unset($data['id']);
    $inserted = $explorer->table(TABLE)->insert($data);
    assert($inserted instanceof Nette\Database\Table\ActiveRow);

    $raw = $pdo->query('SELECT token FROM ' . TABLE . " WHERE id = {$inserted->getPrimary()}")
        ->fetch(PDO::FETCH_ASSOC);
    Assert::null($raw['token']);
});

test('temporal custom types over real DATETIME/TIME/DATE columns (Nette)', function () use ($explorer, $prague): void {
    $hydrator = new Hydrator(Task::class, NetteDatabase::class, $prague, adapters: [PlainDateAdapter::class]);

    $task = $hydrator->fromData($explorer->table(TASK_TABLE)->get(1));

    Assert::same('2026-08-01 12:00:00', $task->dueAt->at->format('Y-m-d H:i:s'));
    Assert::same(5430, $task->estimate->toMinutes());
    Assert::same('2026-08-01', $task->publishedOn->toIso());

    // roundtrip via Explorer: instances pass through, adapter stays format-blind
    $data = $hydrator->toData($task);
    unset($data['id']);
    $inserted = $explorer->table(TASK_TABLE)->insert($data);
    assert($inserted instanceof Nette\Database\Table\ActiveRow);

    $reloaded = $hydrator->fromData($explorer->table(TASK_TABLE)->get($inserted->getPrimary()));
    Assert::same('2026-08-01', $reloaded->publishedOn->toIso());
    Assert::same(5430, $reloaded->estimate->toMinutes());
});
