<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Exception\InvalidEntityException;
use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\InitializationState;
use JakubBoucek\Hydrator\Tests\Fixtures\HookedEntity;
use JakubBoucek\Hydrator\Tests\Fixtures\SimpleEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('isInitialized answers for the stored state of a partial entity', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);
    $entity = $hydrator->fromData(['id' => 1, 'note' => null], allowPartial: true);

    Assert::true($hydrator->isInitialized($entity, 'id'));
    Assert::false($hydrator->isInitialized($entity, 'title'));

    // the reason the API exists for nullable properties: a stored null
    // is initialized — isset() would conflate it with uninitialized
    Assert::true($hydrator->isInitialized($entity, 'note'));
    Assert::false(isset($entity->note));
});

test('hook-free contract: hooks are never invoked, the backing store answers', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);
    $entity = new HookedEntity();

    // isset() would invoke the get hook and crash on the uninitialized
    // backing — the hydrator reads the backing store directly
    Assert::error(fn() => isset($entity->email), Error::class);
    Assert::false($hydrator->isInitialized($entity, 'email'));

    $entity->email = ' Ada@Example.COM ';
    Assert::true($hydrator->isInitialized($entity, 'email'));
});

test('restricted setters are backed state and answer normally', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);
    $entity = new HookedEntity();

    Assert::true($hydrator->isInitialized($entity, 'secret'));   // private(set) with default
    Assert::true($hydrator->isInitialized($entity, 'version'));  // readonly set in constructor
});

test('an unknown or virtual property is a caller bug, not "not set"', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);
    $entity = new HookedEntity();

    Assert::exception(
        fn() => $hydrator->isInitialized($entity, 'fulName'),
        MetadataException::class,
        "~Unknown property 'fulName'.*no such mapped property~",
    );

    foreach (['fullName', 'names'] as $virtual) {
        Assert::exception(
            fn() => $hydrator->isInitialized($entity, $virtual),
            MetadataException::class,
            '~is virtual.*no stored state.*cannot process virtual properties~',
        );
    }
});

test('getInitializedPropertyNames lists the stored state, virtuals never appear', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);

    // fresh instance: only the default and the constructor-set readonly
    Assert::same(
        ['secret', 'version'],
        $hydrator->getInitializedPropertyNames(new HookedEntity()),
    );

    $entity = $hydrator->fromData([
        'id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
    ]);
    Assert::same(
        ['id', 'firstName', 'lastName', 'email', 'secret', 'version'],
        $hydrator->getInitializedPropertyNames($entity),
    );
});

test('the plural mirrors partial hydration and never runs user code', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);
    $entity = $hydrator->fromData(['id' => 1, 'note' => null], allowPartial: true);

    Assert::same(['id', 'note'], $hydrator->getInitializedPropertyNames($entity));

    // hooked entity with a crashing get hook dependency: still safe
    Assert::same(
        ['secret', 'version'],
        new Hydrator(HookedEntity::class, NetteDatabase::class)
            ->getInitializedPropertyNames(new HookedEntity()),
    );
});

test('getInitializationState aggregates the stored state', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    Assert::same(InitializationState::Empty, $hydrator->getInitializationState(new SimpleEntity()));

    $patch = $hydrator->fromData(['id' => 1], allowPartial: true);
    Assert::same(InitializationState::Partial, $hydrator->getInitializationState($patch));

    // a stored null is initialized — Complete includes it
    $full = $hydrator->fromData(['id' => 1, 'title' => 't', 'note' => null]);
    Assert::same(InitializationState::Complete, $hydrator->getInitializationState($full));
});

test('the state counts non-writable backed properties, virtuals never', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);

    // fresh instance: private(set) default + constructor readonly → Partial
    Assert::same(InitializationState::Partial, $hydrator->getInitializationState(new HookedEntity()));

    // full hydration initializes every backed property; the virtual
    // fullName/names have no stored state and do not block Complete
    $entity = $hydrator->fromData([
        'id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
    ]);
    Assert::same(InitializationState::Complete, $hydrator->getInitializationState($entity));
});

class OnlyVirtualEntity implements Entity
{
    public string $label {
        get => 'x';
    }
}

test('an entity with no mapped property is vacuously Empty, never Complete', function (): void {
    $hydrator = new Hydrator(OnlyVirtualEntity::class, Mysql::class);

    Assert::same(InitializationState::Empty, $hydrator->getInitializationState(new OnlyVirtualEntity()));
});

test('a foreign entity instance is refused like everywhere else', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, Mysql::class);

    Assert::exception(
        fn() => $hydrator->isInitialized(new HookedEntity(), 'id'),
        InvalidEntityException::class,
    );
    Assert::exception(
        fn() => $hydrator->getInitializedPropertyNames(new HookedEntity()),
        InvalidEntityException::class,
    );
    Assert::exception(
        fn() => $hydrator->getInitializationState(new HookedEntity()),
        InvalidEntityException::class,
    );
});
