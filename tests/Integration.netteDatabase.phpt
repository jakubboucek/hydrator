<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\HydratorFactory;
use JakubBoucek\Hydrator\Tests\Fixtures\Book;
use Nette\Caching\Storages\MemoryStorage;
use Nette\Database\Connection;
use Nette\Database\Conventions\DiscoveredConventions;
use Nette\Database\Explorer;
use Nette\Database\Structure;
use Tester\Assert;
use Tester\Environment;

require __DIR__ . '/bootstrap.php';

if (!extension_loaded('pdo_sqlite')) {
    Environment::skip('Requires pdo_sqlite.');
}

$connection = new Connection('sqlite::memory:');
$storage = new MemoryStorage();
$structure = new Structure($connection, $storage);
$explorer = new Explorer($connection, $structure, new DiscoveredConventions($structure), $storage);

$connection->query('CREATE TABLE book (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    note TEXT,
    in_stock INTEGER NOT NULL
)');
$explorer->table('book')->insert(['title' => 'First', 'note' => null, 'in_stock' => 1]);
$explorer->table('book')->insert(['title' => 'Second', 'note' => 'used', 'in_stock' => 0]);

$factory = new HydratorFactory(NetteDatabase::class, new DateTimeZone('Europe/Prague'));
$books = $factory->for(Book::class);

test('hydrates an ActiveRow', function () use ($explorer, $books): void {
    $row = $explorer->table('book')->get(1);

    $book = $books->fromData($row);

    Assert::same(1, $book->id);
    Assert::same('First', $book->title);
    Assert::null($book->note);
    Assert::true($book->inStock);
});

test('hydrates a Row fetched by SQL query', function () use ($explorer, $books): void {
    $row = $explorer->query('SELECT * FROM book WHERE id = ?', 2)->fetch();

    $book = $books->fromData($row);

    Assert::same('Second', $book->title);
    Assert::false($book->inStock);
});

test('hydrates a whole Selection, the PK keys of its iteration pass through', function () use ($explorer, $books): void {
    $entities = $books->fromDataSet($explorer->table('book'))->collectMap();

    Assert::same([1, 2], array_keys($entities));
    Assert::same('Second', $entities[2]->title);
});

test('partial entity performs a partial UPDATE', function () use ($explorer, $books): void {
    $book = new Book();
    $book->title = 'First, revised';
    $book->inStock = false;

    $explorer->table('book')->where('id', 1)->update($books->toData($book));

    $reloaded = $books->fromData($explorer->table('book')->get(1));
    Assert::same('First, revised', $reloaded->title);
    Assert::false($reloaded->inStock);
    Assert::null($reloaded->note); // untouched by the partial update
});
