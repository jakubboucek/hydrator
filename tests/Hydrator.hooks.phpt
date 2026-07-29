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
        'names' => 'Grace Hopper',
        'email' => '  Grace@Example.COM  ',
        // no 'full_name', 'secret' nor 'version' field: not required
    ];
}

test('property hooks semantics on hydration', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);
    $entity = $hydrator->fromData(hookedRow());

    // the write-only virtual $names set hook overwrote first/last name
    Assert::same('Grace', $entity->firstName);
    Assert::same('Hopper', $entity->lastName);
    // virtual get-only property computes from the hydrated state
    Assert::same('Grace Hopper', $entity->fullName);
    // backed property hooks apply on both write (trim) and read (lowercase)
    Assert::same('grace@example.com', $entity->email);
});

test('property hooks and visibility semantics on extraction', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);
    $entity = $hydrator->fromData(hookedRow());

    $data = $hydrator->toData($entity);

    // virtual properties have no field, restricted setters are still extracted
    Assert::same(
        [
            'id' => 1,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.com',
            'secret' => 'hidden',
            'version' => 1,
        ],
        $data,
    );
});
