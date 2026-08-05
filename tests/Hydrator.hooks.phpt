<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\HookedEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

function hookedRow(): array
{
    return [
        'id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => '  Grace@Example.COM  ',
        // no 'full_name', 'names', 'secret' nor 'version' field: not required
    ];
}

test('property hooks semantics on hydration', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);
    $entity = $hydrator->fromData(hookedRow());

    Assert::same('Ada', $entity->firstName);
    Assert::same('Lovelace', $entity->lastName);
    // virtual get-only property computes from the hydrated state
    Assert::same('Ada Lovelace', $entity->fullName);
    // backed property hooks apply on both write (trim) and read (lowercase)
    Assert::same('grace@example.com', $entity->email);
});

test('virtual set-hook property is never hydrated — its field is foreign data', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);

    // a 'names' key is silently ignored like any extra field: the set
    // hook never runs, first/last name keep their hydrated values
    $entity = $hydrator->fromData(hookedRow() + ['names' => 'Grace Hopper']);

    Assert::same('Ada', $entity->firstName);
    Assert::same('Lovelace', $entity->lastName);

    // the set hook stays the application's own interface
    $entity->names = 'Grace Hopper';
    Assert::same('Grace', $entity->firstName);
    Assert::same('Hopper', $entity->lastName);
});

test('property hooks and visibility semantics on extraction', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);
    $entity = $hydrator->fromData(hookedRow());

    $data = $hydrator->toData($entity);

    // virtual properties have no field, restricted setters are still extracted
    Assert::same(
        [
            'id' => 1,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'grace@example.com',
            'secret' => 'hidden',
            'version' => 1,
        ],
        $data,
    );
});
