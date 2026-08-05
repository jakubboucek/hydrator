<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\Customer;
use JakubBoucek\Hydrator\Tests\Fixtures\HookedEntity;
use JakubBoucek\Hydrator\Tests\Fixtures\SimpleEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('allowPartial: missing fields leave properties uninitialized, extraction mirrors it', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    $entity = $hydrator->fromData(['id' => 1], allowPartial: true);

    Assert::same(1, $entity->id);
    Assert::false(new ReflectionProperty(SimpleEntity::class, 'title')->isInitialized($entity));
    Assert::exception(
        fn() => $entity->title,
        Error::class,
        '~must not be accessed before initialization~',
    );

    // the symmetry: a sparse SELECT produces a sparse UPDATE
    Assert::same(['id' => 1], $hydrator->toData($entity));
});

test('the default stays strict — allowPartial is per call only', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    Assert::exception(
        fn() => $hydrator->fromData(['id' => 1]),
        HydrationException::class,
        "~Missing field 'title'~",
    );
});

test('allowPartial with into: acts as a merge — absent fields keep current values', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);
    $entity = $hydrator->fromData(['id' => 1, 'title' => 'Original', 'note' => 'kept']);

    $merged = $hydrator->fromData(['title' => 'Patched'], into: $entity, allowPartial: true);

    Assert::same($entity, $merged);
    Assert::same('Patched', $entity->title);
    Assert::same('kept', $entity->note);
    Assert::same(1, $entity->id);
});

test('rejectUnknown: a data key mapping to no property fails loudly', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);
    $row = ['id' => 1, 'title' => 't', 'note' => null];

    // typo scenario: allowPartial alone would silently skip $title
    Assert::exception(
        fn() => $hydrator->fromData(['id' => 1, 'tilte' => 't'], allowPartial: true, rejectUnknown: true),
        HydrationException::class,
        "~Unknown fields 'tilte' in data for .*SimpleEntity~",
    );

    // full-strict profile: exact 1:1 match passes
    Assert::same('t', $hydrator->fromData($row, rejectUnknown: true)->title);

    Assert::exception(
        fn() => $hydrator->fromData(['surplus' => 1] + $row, rejectUnknown: true),
        HydrationException::class,
        "~Unknown fields 'surplus'~",
    );
});

test('rejectUnknown knows fields of non-writable backed properties, rejects virtual ones', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);
    $row = [
        'id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'secret' => 'ignored',
        'version' => 7,
    ];

    // secret/version are extraction-only backed fields — a roundtrip of
    // a complete row must not trip the unknown-field check
    $entity = $hydrator->fromData($row, rejectUnknown: true);
    Assert::same('Ada', $entity->firstName);

    // a virtual property's field is foreign data like any unknown key,
    // only the error message points out the match
    Assert::exception(
        fn() => $hydrator->fromData($row + ['full_name' => 'x'], rejectUnknown: true),
        HydrationException::class,
        "~Unknown fields 'full_name'.*virtual property.*fullName.*cannot process~",
    );
});

test('allowPartial applies to structs as well: the field must be present for the instance rule', function (): void {
    $hydrator = new Hydrator(Customer::class, NetteDatabase::class);

    $customer = $hydrator->fromData(['id' => 1, 'name' => 'Ada'], allowPartial: true);

    Assert::false(new ReflectionProperty(Customer::class, 'contact')->isInitialized($customer));
});

test('fromDataSet passes both switches through', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    $entities = iterator_to_array($hydrator->fromDataSet(
        [['id' => 10], ['id' => 20]],
        keyBy: 'id',
        allowPartial: true,
    ));
    Assert::same([10, 20], array_keys($entities));

    Assert::exception(
        fn() => iterator_to_array($hydrator->fromDataSet([['id' => 1, 'oops' => 2]], allowPartial: true, rejectUnknown: true)),
        HydrationException::class,
        "~Unknown fields 'oops'~",
    );
});
