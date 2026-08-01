<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Format\Json;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\PlainDateAdapter;
use JakubBoucek\Hydrator\Tests\Fixtures\Task;
use JakubBoucek\Hydrator\Tests\Fixtures\ThirdParty\PlainDate;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$prague = new DateTimeZone('Europe/Prague');

function taskHydrator(string $format, DateTimeZone $timeZone): Hydrator
{
    return new Hydrator(Task::class, $format, $timeZone, adapters: [PlainDateAdapter::class]);
}

test('temporal custom types over the Mysql format (strings both ways)', function () use ($prague): void {
    $hydrator = taskHydrator(Mysql::class, $prague);

    $task = $hydrator->fromData([
        'id' => '1',
        'due_at' => '2026-08-01T10:00:00+00:00',   // foreign offset recalculated by the format
        'estimate' => '90:30:00',                  // full TIME domain stays available
        'published_on' => '2026-08-01 00:00:00',
    ]);

    Assert::same('2026-08-01 12:00:00', $task->dueAt->at->format('Y-m-d H:i:s'));
    Assert::same('Europe/Prague', $task->dueAt->at->getTimezone()->getName());
    Assert::true($task->dueAt->isOverdue(new DateTimeImmutable('2027-01-01', $prague)));
    Assert::same(5430, $task->estimate->toMinutes());
    Assert::type(PlainDate::class, $task->publishedOn);
    Assert::same('2026-08-01', $task->publishedOn->toIso());

    $data = $hydrator->toData($task);
    Assert::same('2026-08-01 12:00:00', $data['due_at']);
    Assert::same('90:30:00', $data['estimate']);
    Assert::same('2026-08-01 00:00:00', $data['published_on']);
});

test('the same custom types are format-blind: NetteDatabase gets instances', function () use ($prague): void {
    $hydrator = taskHydrator(NetteDatabase::class, $prague);

    $task = $hydrator->fromData([
        'id' => 1,
        'due_at' => new DateTimeImmutable('2026-08-01 12:00:00', $prague),
        'estimate' => new DateInterval('PT90H30M'),
        'published_on' => new DateTimeImmutable('2026-08-01 00:00:00', $prague),
    ]);

    $data = $hydrator->toData($task);

    // instance pass-through on export — the adapter code did not change at all
    Assert::type(DateTimeImmutable::class, $data['due_at']);
    Assert::type(DateInterval::class, $data['estimate']);
    Assert::type(DateTimeImmutable::class, $data['published_on']);
});

test('and Json renders RFC 3339 — still the same custom types', function () use ($prague): void {
    $hydrator = taskHydrator(Json::class, $prague);

    $task = $hydrator->fromData([
        'id' => 1,
        'dueAt' => '2026-08-01T12:00:00+02:00',
        'estimate' => '90:30:00',
        'publishedOn' => '2026-08-01T00:00:00+02:00',
    ]);

    $data = $hydrator->toData($task);
    Assert::same('2026-08-01T12:00:00+02:00', $data['dueAt']);
    Assert::same('90:30:00', $data['estimate']);
});
