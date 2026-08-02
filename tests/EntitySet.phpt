<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\EntitySet;
use JakubBoucek\Hydrator\Exception\StreamException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\SimpleEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

function makeSet(array $rows = [
    ['id' => 10, 'title' => 'A', 'note' => null],
    ['id' => 20, 'title' => 'B', 'note' => null],
    ['id' => 30, 'title' => 'C', 'note' => null],
], ?string $keyBy = null): EntitySet
{
    return new Hydrator(SimpleEntity::class, Mysql::class)->fromDataSet($rows, keyBy: $keyBy);
}

test('foreach iterates the stream with its keys', function (): void {
    $titles = [];
    foreach (makeSet(keyBy: 'id') as $id => $entity) {
        $titles[$id] = $entity->title;
    }

    Assert::same([10 => 'A', 20 => 'B', 30 => 'C'], $titles);
});

test('collectList materializes a plain list, keys are discarded', function (): void {
    $entities = makeSet(keyBy: 'id')->collectList();

    Assert::same([0, 1, 2], array_keys($entities));
    Assert::same('B', $entities[1]->title);
});

test('collectMap materializes an array preserving the stream keys', function (): void {
    $entities = makeSet(keyBy: 'id')->collectMap();

    Assert::same([10, 20, 30], array_keys($entities));
    Assert::same('B', $entities[20]->title);

    // without keying the map simply keeps the sequential source keys
    Assert::same([0, 1, 2], array_keys(makeSet()->collectMap()));
});

test('empty data set collects to empty arrays and iterates nothing', function (): void {
    Assert::same([], makeSet([])->collectList());
    Assert::same([], makeSet([])->collectMap());

    foreach (makeSet([]) as $entity) {
        Assert::fail('An empty set must not yield.');
    }
});

test('every second consumption throws a StreamException', function (): void {
    $combinations = [
        [fn(EntitySet $set) => iterator_to_array($set), fn(EntitySet $set) => iterator_to_array($set)],
        [fn(EntitySet $set) => iterator_to_array($set), fn(EntitySet $set) => $set->collectList()],
        [fn(EntitySet $set) => $set->collectList(), fn(EntitySet $set) => $set->collectMap()],
        [fn(EntitySet $set) => $set->collectMap(), fn(EntitySet $set) => $set->getIterator()],
        [fn(EntitySet $set) => $set->collectList(), fn(EntitySet $set) => iterator_to_array($set)],
    ];

    foreach ($combinations as [$first, $second]) {
        $set = makeSet();
        $first($set);
        Assert::exception(
            fn() => $second($set),
            StreamException::class,
            '~already consumed.*single-pass~',
        );
    }
});

test('consumption interrupted by break still counts as the one allowed pass', function (): void {
    $set = makeSet();
    foreach ($set as $entity) {
        break;
    }

    Assert::exception(
        fn() => $set->collectList(),
        StreamException::class,
        '~already consumed~',
    );
});

test('the generator allows peeking at the first entity and continuing the full pass', function (): void {
    $generator = makeSet()->getIterator();

    $first = $generator->current();
    Assert::same('A', $first->title);

    // current() does not advance: a foreach over the same generator
    // starts from the first element and delivers the whole stream
    $titles = [];
    foreach ($generator as $entity) {
        $titles[] = $entity->title;
    }
    Assert::same(['A', 'B', 'C'], $titles);
});
