<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\HydratorFactory;
use JakubBoucek\Hydrator\Tests\Fixtures\Article;
use JakubBoucek\Hydrator\Tests\Fixtures\SimpleEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('factory caches hydrators per entity class and format', function (): void {
    $factory = new HydratorFactory(NetteDatabase::class, new DateTimeZone('Europe/Prague'));

    $first = $factory->for(Article::class);
    Assert::same($first, $factory->for(Article::class));
    Assert::same($first, $factory->for(Article::class, NetteDatabase::class));

    Assert::notSame($first, $factory->for(Article::class, Mysql::class));
    Assert::notSame($first, $factory->for(SimpleEntity::class));
});

test('the preferred format is used when none is requested', function (): void {
    $factory = new HydratorFactory(Mysql::class);

    $article = $factory->for(Article::class)->fromData([
        'id' => '1',
        'title' => 't',
        'note' => null,
        'published' => '0',
        'created_at' => '2026-01-01 00:00:00',
        'published_on' => '2026-01-01',
        'reading_time' => '00:01:00',
        'status' => 'draft',
        'level' => '1',
        'view_count' => '0',
        'raw_meta' => null,
    ]);

    Assert::false($article->published);
});
