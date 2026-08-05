<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\Book;
use JakubBoucek\Hydrator\Tests\Fixtures\HookedEntity;
use JakubBoucek\Hydrator\Tests\Fixtures\NamedEntity;
use JakubBoucek\Hydrator\Tests\Fixtures\SimpleEntity;
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

/** Selection-like source: iterates rows keyed by the primary key, the way Nette Selection does. */
class KeyedSource implements IteratorAggregate
{
    public function __construct(
        private readonly array $rows,
    ) {
    }

    public function getIterator(): Generator
    {
        foreach ($this->rows as $row) {
            yield $row['id'] => $row;
        }
    }
}

test('fromDataSet is a lazy single-pass stream, nothing is buffered ahead', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    $consumed = 0;
    $source = (function () use (&$consumed) {
        foreach (rows() as $row) {
            $consumed++;
            yield $row;
        }
    })();

    $set = $hydrator->fromDataSet($source);
    Assert::same(0, $consumed);

    $generator = $set->getIterator();
    Assert::same(0, $consumed);

    $first = $generator->current();
    Assert::same('A', $first->title);
    Assert::same(1, $consumed);

    // the rest of the stream (including the current item) hydrates on demand
    $entities = iterator_to_array($generator, preserve_keys: false);
    Assert::count(3, $entities);
    Assert::same(3, $consumed);
});

test('source keys pass through transparently, regardless of format', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    // a plain list yields sequential keys
    $entities = iterator_to_array($hydrator->fromDataSet(rows()));
    Assert::same([0, 1, 2], array_keys($entities));

    // a keyed array keeps its keys
    $entities = iterator_to_array($hydrator->fromDataSet(['a' => rows()[0], 'b' => rows()[1]]));
    Assert::same(['a', 'b'], array_keys($entities));

    // a Selection-like source keyed by the primary key keeps the PK keys
    $entities = iterator_to_array($hydrator->fromDataSet(new KeyedSource(rows())));
    Assert::same([10, 20, 30], array_keys($entities));
    Assert::same('B', $entities[20]->title);
});

test('explicit keyBy re-keys the stream by an entity property', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    // keyBy overrides the source keys
    $entities = iterator_to_array($hydrator->fromDataSet(['a' => rows()[0], 'b' => rows()[1]], keyBy: 'id'));

    Assert::same([10, 20], array_keys($entities));
    Assert::same('B', $entities[20]->title);
});

test('the key is read from the hydrated entity: properly typed, format naming never leaks', function (): void {
    // stringified driver values key as the property type, not the raw string
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);
    $entities = iterator_to_array(
        $hydrator->fromDataSet([['id' => '10', 'title' => 'A', 'note' => null]], keyBy: 'id'),
    );
    Assert::same([10], array_keys($entities));

    // the caller speaks property names, the field name of the format is internal
    $hydrator = new Hydrator(NamedEntity::class, Mysql::class);
    $entities = iterator_to_array($hydrator->fromDataSet([
        ['id' => 1, 'some__name' => 'alpha', 'mysql__col' => 'x', 'db__only' => 'y'],
        ['id' => 2, 'some__name' => 'beta', 'mysql__col' => 'x', 'db__only' => 'y'],
    ], keyBy: 'someName'));
    Assert::same(['alpha', 'beta'], array_keys($entities));
});

test('an unusable keyBy property throws immediately, before the source is touched', function (): void {
    $cases = [
        [SimpleEntity::class, 'uuid', "~Unknown keyBy property 'uuid'~"],
        [Book::class, 'inStock', '~must be typed int or string~'],
        [HookedEntity::class, 'version', '~never hydrated~'],
        [HookedEntity::class, 'names', '~never hydrated~'],
        [SimpleEntity::class, 'note', '~must not be nullable~'],
    ];

    foreach ($cases as [$entityClass, $keyBy, $message]) {
        $hydrator = new Hydrator($entityClass, NetteDatabase::class);
        Assert::exception(
            fn() => $hydrator->fromDataSet(rows(), keyBy: $keyBy),
            MetadataException::class,
            $message,
        );
    }
});

test('missing key field and invalid items throw', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    // strict mode: the standard missing-field error covers the key property
    Assert::exception(
        fn() => iterator_to_array($hydrator->fromDataSet([['title' => 'A', 'note' => null]], keyBy: 'id')),
        HydrationException::class,
        "~Missing field 'id' in data for property~",
    );
    // allowPartial: an uninitialized key property cannot key the stream
    Assert::exception(
        fn() => iterator_to_array(
            $hydrator->fromDataSet([['title' => 'A']], keyBy: 'id', allowPartial: true),
        ),
        HydrationException::class,
        '~Cannot key the stream by .*\$id.*allowPartial~',
    );
    Assert::exception(
        fn() => iterator_to_array($hydrator->fromDataSet(['scalar item'])),
        HydrationException::class,
        '~must be an array or Traversable~',
    );
});
