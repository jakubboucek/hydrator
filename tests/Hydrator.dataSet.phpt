<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\ValidatedEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

function rows(): array
{
    return [
        ['id' => 10, 'title' => 'A', 'note' => null],
        ['id' => 20, 'title' => 'B', 'note' => null],
        ['id' => 30, 'title' => 'C', 'note' => null],
    ];
}

/** Selection-like object: iterates rows, exposes getPrimary(). */
class FakeSelection implements IteratorAggregate
{
    public function __construct(
        private readonly array $rows,
        private readonly string|array|null $primary,
    ) {
    }

    public function getIterator(): Generator
    {
        yield from $this->rows;
    }

    public function getPrimary(bool $throw = true): string|array|null
    {
        return $this->primary;
    }
}

test('fromDataSet is a lazy single-pass stream, nothing is buffered ahead', function (): void {
    $hydrator = new Hydrator(ValidatedEntity::class, Mysql::class);

    $consumed = 0;
    $source = (function () use (&$consumed) {
        foreach (rows() as $row) {
            $consumed++;
            yield $row;
        }
    })();

    $generator = $hydrator->fromDataSet($source);
    Assert::same(0, $consumed);

    $first = $generator->current();
    Assert::same('A', $first->title);
    Assert::same(1, $consumed);

    // the rest of the stream (including the current item) hydrates on demand
    $entities = iterator_to_array($generator, preserve_keys: false);
    Assert::count(3, $entities);
    Assert::same(3, $consumed);
});

test('explicit keyBy keys the stream by a data field', function (): void {
    $hydrator = new Hydrator(ValidatedEntity::class, Mysql::class);

    $entities = iterator_to_array($hydrator->fromDataSet(rows(), keyBy: 'id'));

    Assert::same([10, 20, 30], array_keys($entities));
    Assert::same('B', $entities[20]->title);
});

test('NetteDatabase format auto-detects the primary key via duck-typing', function (): void {
    $hydrator = new Hydrator(ValidatedEntity::class, NetteDatabase::class);

    $entities = iterator_to_array($hydrator->fromDataSet(new FakeSelection(rows(), 'id')));

    Assert::same([10, 20, 30], array_keys($entities));
});

test('composite primary key falls back to sequential keys', function (): void {
    $hydrator = new Hydrator(ValidatedEntity::class, NetteDatabase::class);

    $entities = iterator_to_array($hydrator->fromDataSet(new FakeSelection(rows(), ['id', 'title'])));

    Assert::same([0, 1, 2], array_keys($entities));
});

test('Mysql format has no auto-detection, keys are sequential', function (): void {
    $hydrator = new Hydrator(ValidatedEntity::class, Mysql::class);

    $entities = iterator_to_array($hydrator->fromDataSet(new FakeSelection(rows(), 'id')));

    Assert::same([0, 1, 2], array_keys($entities));
});

test('missing key field and invalid items throw', function (): void {
    $hydrator = new Hydrator(ValidatedEntity::class, Mysql::class);

    Assert::exception(
        fn() => iterator_to_array($hydrator->fromDataSet(rows(), keyBy: 'uuid')),
        HydrationException::class,
        "~Missing key field 'uuid'~",
    );
    Assert::exception(
        fn() => iterator_to_array($hydrator->fromDataSet(['scalar item'])),
        HydrationException::class,
        '~must be an array or Traversable~',
    );
});
