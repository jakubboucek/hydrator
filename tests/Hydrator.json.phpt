<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Format\Json;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\Article;
use JakubBoucek\Hydrator\Tests\Fixtures\ArticleStatus;
use JakubBoucek\Hydrator\Tests\Fixtures\NamedEntity;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$prague = new DateTimeZone('Europe/Prague');

function jsonData(): array
{
    return [
        'id' => 7,
        'title' => 'Hello',
        'note' => null,
        'published' => true,
        'createdAt' => '2026-07-29T10:30:00+00:00',
        'publishedOn' => '2026-07-01',
        'readingTime' => 'PT12M30S',
        'status' => 'published',
        'level' => 2,
        'viewCount' => 3,
        'rawMeta' => ['a' => 1],
    ];
}

test('hydrates decoded JSON: camelCase fields, RFC 3339 recalculated to the app TZ', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Json::class, $prague);
    $article = $hydrator->fromData(jsonData());

    Assert::same(7, $article->id);
    Assert::true($article->published);
    // foreign offset (+00:00) recalculated into Europe/Prague (+02:00 in summer)
    Assert::same('2026-07-29 12:30:00', $article->createdAt->format('Y-m-d H:i:s'));
    Assert::same('Europe/Prague', $article->createdAt->getTimezone()->getName());
    Assert::same('2026-07-01 00:00:00', $article->publishedOn->format('Y-m-d H:i:s'));
    Assert::same(12, $article->readingTime->i);
    Assert::same(30, $article->readingTime->s);
    Assert::same(ArticleStatus::Published, $article->status);
    Assert::same(['a' => 1], $article->rawMeta);
});

test('extracts to JSON representation', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Json::class, $prague);
    $article = $hydrator->fromData(jsonData());

    $data = $hydrator->toData($article);

    Assert::same('2026-07-29T12:30:00+02:00', $data['createdAt']);
    Assert::same('2026-07-01', $data['publishedOn']);
    Assert::same('PT12M30S', $data['readingTime']);
    Assert::true($data['published']);
    Assert::same('published', $data['status']);
    Assert::same(2, $data['level']);
    Assert::same(['a' => 1], $data['rawMeta']);
});

test('ISO 8601 durations survive the roundtrip', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Json::class, $prague);

    foreach (['P1DT2H', '-PT120H5M1S', 'PT1M30.25S', '-P2Y3M4DT5H6M7.5S', 'PT0S'] as $duration) {
        $data = ['readingTime' => $duration] + jsonData();
        $article = $hydrator->fromData($data);
        Assert::same($duration, $hydrator->toData($article)['readingTime'], "roundtrip of {$duration}");
    }

    $inverted = $hydrator->fromData(['readingTime' => '-PT1H'] + jsonData());
    Assert::same(1, $inverted->readingTime->invert);
    Assert::same(1, $inverted->readingTime->h);
});

test('JSON booleans are strict', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Json::class, $prague);

    Assert::exception(
        fn() => $hydrator->fromData(['published' => 1] + jsonData()),
        HydrationException::class,
        '~Cannot hydrate property .*::\\$published.*Expected bool~',
    );
});

test('invalid duration is rejected with context', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Json::class, $prague);

    Assert::exception(
        fn() => $hydrator->fromData(['readingTime' => '12:30'] + jsonData()),
        HydrationException::class,
        "~Cannot hydrate property .*::\\\$readingTime.*Invalid ISO 8601 duration '12:30'~",
    );
});

test('#[Name] scoping: Json is not a database format', function (): void {
    $hydrator = new Hydrator(NamedEntity::class, Json::class);

    $entity = new NamedEntity();
    $entity->id = 1;
    $entity->someName = 'a';
    $entity->scoped = 'b';
    $entity->family = 'c';

    // catch-all overrides apply, DatabaseFormat scope does not, convention keeps camelCase
    Assert::same(
        ['id' => 1, 'some__name' => 'a', 'generic_col' => 'b', 'family' => 'c'],
        $hydrator->toData($entity),
    );
});
