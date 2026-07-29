<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Exception\InvalidEntityException;
use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\Article;
use JakubBoucek\Hydrator\Tests\Fixtures\SimpleEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('missing field throws with property context', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, NetteDatabase::class);

    Assert::exception(
        fn() => $hydrator->fromData(['id' => 1, 'note' => null]),
        HydrationException::class,
        "~Missing field 'title'.*SimpleEntity::\\\$title~",
    );
});

test('null in a non-nullable property throws', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, NetteDatabase::class);

    Assert::exception(
        fn() => $hydrator->fromData(['id' => 1, 'title' => null, 'note' => null]),
        HydrationException::class,
        "~Field 'title' is null but property .*::\\\$title is not nullable~",
    );
});

test('null keeps the default value of a non-nullable property', function (): void {
    $hydrator = new Hydrator(Article::class, NetteDatabase::class);
    $row = [
        'id' => 1,
        'title' => 't',
        'note' => null,
        'published' => true,
        'created_at' => new DateTimeImmutable(),
        'published_on' => new DateTimeImmutable(),
        'reading_time' => new DateInterval('PT1M'),
        'status' => 'draft',
        'level' => 1,
        'view_count' => null,
        'raw_meta' => null,
    ];

    Assert::same(0, $hydrator->fromData($row)->viewCount);
});

test('invalid values are wrapped with property context', function (): void {
    $hydrator = new Hydrator(Article::class, Mysql::class);
    $valid = [
        'id' => 1,
        'title' => 't',
        'note' => null,
        'published' => '1',
        'created_at' => '2026-01-01 00:00:00',
        'published_on' => '2026-01-01',
        'reading_time' => '00:01:00',
        'status' => 'draft',
        'level' => '1',
        'view_count' => '0',
        'raw_meta' => null,
    ];

    $badEnum = ['status' => 'nonsense'] + $valid;
    Assert::exception(
        fn() => $hydrator->fromData($badEnum),
        HydrationException::class,
        '~Cannot hydrate property .*::\\$status.*nonsense~',
    );

    $badBool = ['published' => 'yes'] + $valid;
    Assert::exception(
        fn() => $hydrator->fromData($badBool),
        HydrationException::class,
        '~Cannot hydrate property .*::\\$published.*bool-like~',
    );

    $badDate = ['created_at' => 'not-a-date'] + $valid;
    Assert::exception(
        fn() => $hydrator->fromData($badDate),
        HydrationException::class,
        '~Cannot hydrate property .*::\\$createdAt~',
    );
});

test('foreign instances are rejected', function (): void {
    $hydrator = new Hydrator(SimpleEntity::class, NetteDatabase::class);

    // a different entity class: rejected by the hydrator with a clear message
    Assert::exception(
        fn() => $hydrator->toData(new Article()),
        InvalidEntityException::class,
        '~must be an instance of .*SimpleEntity.*got .*Article~',
    );
    Assert::exception(
        fn() => $hydrator->fromData(['id' => 1], into: new Article()),
        InvalidEntityException::class,
    );
    // a non-entity object: rejected even earlier by the native Entity type
    Assert::exception(
        fn() => $hydrator->toData(new stdClass()), // @phpstan-ignore argument.type
        TypeError::class,
    );
});

test('invalid construction is rejected', function (): void {
    Assert::exception(
        fn() => new Hydrator('App\Missing\Entity', NetteDatabase::class),
        MetadataException::class,
        '~does not exist~',
    );
    Assert::exception(
        fn() => new Hydrator(Article::class, stdClass::class),
        MetadataException::class,
        '~must extend~',
    );
});
