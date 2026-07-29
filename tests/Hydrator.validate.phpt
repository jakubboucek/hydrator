<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\ValidationException;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\HookedEntity;
use JakubBoucek\Hydrator\Tests\Fixtures\ValidatedEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('completeness: uninitialized non-nullable properties are reported', function (): void {
    $hydrator = new Hydrator(ValidatedEntity::class, NetteDatabase::class);

    $entity = new ValidatedEntity();
    $entity->id = 1;
    // $title stays uninitialized, nullable $note may stay uninitialized

    Assert::false($hydrator->isComplete($entity));
    Assert::exception(
        fn() => $hydrator->validate($entity),
        ValidationException::class,
        '~incomplete.*\$title~',
    );

    $entity->title = 'Done';
    Assert::true($hydrator->isComplete($entity));
    $hydrator->validate($entity);
    Assert::true(true);
});

test('SelfValidating rules run after the completeness check', function (): void {
    $hydrator = new Hydrator(ValidatedEntity::class, NetteDatabase::class);

    $entity = new ValidatedEntity();
    $entity->id = 1;
    $entity->title = '';

    Assert::exception(
        fn() => $hydrator->validate($entity),
        ValidationException::class,
        '~Title must not be empty~',
    );
});

test('virtual properties are ignored by the completeness check', function (): void {
    $hydrator = new Hydrator(HookedEntity::class, NetteDatabase::class);

    $entity = new HookedEntity();
    $entity->id = 1;
    $entity->firstName = 'Ada';
    $entity->lastName = 'Lovelace';
    $entity->email = 'ada@example.com';

    Assert::true($hydrator->isComplete($entity));
});
