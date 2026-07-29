<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\ExtractionException;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\Article;
use JakubBoucek\Hydrator\Tests\Fixtures\ArticleStatus;
use JakubBoucek\Hydrator\Tests\Fixtures\Level;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$prague = new DateTimeZone('Europe/Prague');

function mysqlRow(): array
{
    return [
        'id' => '7',
        'title' => 'Hello',
        'note' => null,
        'published' => '1',
        'created_at' => '2026-07-29 10:30:00',
        'published_on' => '2026-07-01',
        'reading_time' => '00:12:30',
        'status' => 'published',
        'level' => '2',
        'view_count' => '3',
        'raw_meta' => '{"a":1}',
    ];
}

test('hydrates raw MySQL string row to typed entity', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Mysql::class, $prague);
    $article = $hydrator->fromData(mysqlRow());

    Assert::same(7, $article->id);
    Assert::same('Hello', $article->title);
    Assert::null($article->note);
    Assert::true($article->published);
    Assert::same('2026-07-29 10:30:00', $article->createdAt->format('Y-m-d H:i:s'));
    Assert::same('Europe/Prague', $article->createdAt->getTimezone()->getName());
    Assert::same('2026-07-01 00:00:00', $article->publishedOn->format('Y-m-d H:i:s'));
    Assert::same('00:12:30', $article->readingTime->format('%H:%I:%S'));
    Assert::same(ArticleStatus::Published, $article->status);
    Assert::same(Level::High, $article->level);
    Assert::same(3, $article->viewCount);
    Assert::same('{"a":1}', $article->rawMeta);
});

test('date-time string with a foreign offset is converted to the app time zone', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Mysql::class, $prague);
    $row = mysqlRow();
    $row['created_at'] = '2026-07-29T10:30:00+00:00';

    $article = $hydrator->fromData($row);

    // Europe/Prague is UTC+2 in summer
    Assert::same('2026-07-29 12:30:00', $article->createdAt->format('Y-m-d H:i:s'));
    Assert::same('Europe/Prague', $article->createdAt->getTimezone()->getName());
});

test('date-time instance is accepted and converted to the app time zone', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Mysql::class, $prague);
    $row = mysqlRow();
    $row['created_at'] = new DateTimeImmutable('2026-07-29 10:30:00', new DateTimeZone('UTC'));

    $article = $hydrator->fromData($row);

    Assert::same('2026-07-29 12:30:00', $article->createdAt->format('Y-m-d H:i:s'));
});

test('extracts the entity back to raw MySQL representation', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Mysql::class, $prague);
    $article = $hydrator->fromData(mysqlRow());

    $data = $hydrator->toData($article);

    Assert::same(7, $data['id']);
    Assert::same(1, $data['published']);
    Assert::same('2026-07-29 10:30:00', $data['created_at']);
    Assert::same('2026-07-01', $data['published_on']);
    Assert::same('00:12:30', $data['reading_time']);
    Assert::same('published', $data['status']);
    Assert::same(2, $data['level']);
    Assert::null($data['note']);
});

test('negative and long intervals survive the roundtrip', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Mysql::class, $prague);
    $row = mysqlRow();
    $row['reading_time'] = '-120:05:01';

    $article = $hydrator->fromData($row);
    Assert::same(1, $article->readingTime->invert);
    Assert::same(120, $article->readingTime->h);

    $data = $hydrator->toData($article);
    Assert::same('-120:05:01', $data['reading_time']);
});

test('interval with a year/month part cannot be extracted as time', function () use ($prague): void {
    $hydrator = new Hydrator(Article::class, Mysql::class, $prague);
    $article = $hydrator->fromData(mysqlRow());
    $article->readingTime = new DateInterval('P1M');

    Assert::exception(
        fn() => $hydrator->toData($article),
        ExtractionException::class,
        '~readingTime~',
    );
});
