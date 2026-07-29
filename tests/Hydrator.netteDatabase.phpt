<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\Article;
use JakubBoucek\Hydrator\Tests\Fixtures\ArticleStatus;
use JakubBoucek\Hydrator\Tests\Fixtures\Level;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$prague = new DateTimeZone('Europe/Prague');

function netteRow(): array
{
    return [
        'id' => 7,
        'title' => 'Hello',
        'note' => null,
        'published' => true,
        'created_at' => new DateTimeImmutable('2026-07-29 10:30:00', new DateTimeZone('UTC')),
        'published_on' => new DateTimeImmutable('2026-07-01 00:00:00', new DateTimeZone('Europe/Prague')),
        'reading_time' => new DateInterval('PT12M30S'),
        'status' => 'published',
        'level' => 2,
        'view_count' => 3,
        'raw_meta' => null,
    ];
}

test('hydrates already-typed values with pass-through and TZ normalization', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, NetteDatabase::class, $prague);

    // Traversable row (like Nette Row/ActiveRow)
    $article = $hydrator->fromData(new ArrayIterator(netteRow()));

    Assert::same(7, $article->id);
    Assert::true($article->published);
    // UTC instance converted to app TZ (same instant)
    Assert::same('2026-07-29 12:30:00', $article->createdAt->format('Y-m-d H:i:s'));
    Assert::same('Europe/Prague', $article->createdAt->getTimezone()->getName());
    Assert::same(ArticleStatus::Published, $article->status);
    Assert::same(Level::High, $article->level);
    Assert::null($article->rawMeta);
});

test('extraction passes instances through untouched', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, NetteDatabase::class, $prague);
    $article = $hydrator->fromData(netteRow());

    $data = $hydrator->toData($article);

    Assert::true($data['published']);
    Assert::same($article->createdAt, $data['created_at']);
    Assert::same($article->publishedOn, $data['published_on']);
    Assert::same($article->readingTime, $data['reading_time']);
    Assert::same('published', $data['status']);
    Assert::same(2, $data['level']);
});

test('partial entity extracts only initialized properties', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, NetteDatabase::class, $prague);

    $article = new Article();
    $article->title = 'Only title';

    // viewCount has a default value, so it is initialized as well
    Assert::same(['title' => 'Only title', 'view_count' => 0], $hydrator->toData($article));
});

test('re-hydration into an existing instance', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, NetteDatabase::class, $prague);
    $article = $hydrator->fromData(netteRow());

    $row = netteRow();
    $row['title'] = 'Updated';
    $second = $hydrator->fromData($row, into: $article);

    Assert::same($article, $second);
    Assert::same('Updated', $article->title);
});
