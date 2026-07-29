<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Json;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenDateAndTime;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenTimeOnString;
use JakubBoucek\Hydrator\Tests\Fixtures\Schedule;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Fixtures/Broken.php';

$prague = new DateTimeZone('Europe/Prague');

test('#[Type\Time] hydrates to a DateTimeImmutable pinned to 0001-01-01', function () use ($prague): void {
    $hydrator = new Hydrator(Schedule::class, Mysql::class, $prague);

    $schedule = $hydrator->fromData([
        'id' => 1,
        'starts_at' => '08:30:00',
        'duration' => '-120:05:01',
        'ends_at' => null,
    ]);

    Assert::same('0001-01-01 08:30:00', $schedule->startsAt->format('Y-m-d H:i:s'));
    Assert::same('Europe/Prague', $schedule->startsAt->getTimezone()->getName());
    Assert::null($schedule->endsAt);
    // the DateInterval property next to it keeps the full TIME domain
    Assert::same(1, $schedule->duration->invert);
    Assert::same(120, $schedule->duration->h);
});

test('extraction renders the wall clock as HH:MM:SS', function () use ($prague): void {
    $hydrator = new Hydrator(Schedule::class, Mysql::class, $prague);
    $schedule = $hydrator->fromData([
        'id' => 1,
        'starts_at' => '08:30:00.25',
        'duration' => '00:45:00',
        'ends_at' => '23:59:59',
    ]);

    $data = $hydrator->toData($schedule);

    Assert::same('08:30:00.25', $data['starts_at']);
    Assert::same('23:59:59', $data['ends_at']);
    Assert::same('00:45:00', $data['duration']);
});

test('the day range is enforced, unlike the DateInterval codec', function () use ($prague): void {
    $hydrator = new Hydrator(Schedule::class, Mysql::class, $prague);
    $valid = ['id' => 1, 'starts_at' => '08:30:00', 'duration' => '00:45:00', 'ends_at' => null];

    Assert::exception(
        fn() => $hydrator->fromData(['starts_at' => '24:00:00'] + $valid),
        HydrationException::class,
        '~out of the day range.*DateInterval property~',
    );
    Assert::exception(
        fn() => $hydrator->fromData(['starts_at' => '80:00:00'] + $valid),
        HydrationException::class,
        '~out of the day range~',
    );
    Assert::exception(
        fn() => $hydrator->fromData(['starts_at' => '-08:00:00'] + $valid),
        HydrationException::class,
        '~Expected time-of-day string~',
    );
});

test('NetteDatabase converts DateInterval from MySQL TIME within the day scope', function () use ($prague): void {
    $hydrator = new Hydrator(Schedule::class, NetteDatabase::class, $prague);
    $row = [
        'id' => 1,
        'starts_at' => new DateInterval('PT8H30M'),
        'duration' => new DateInterval('PT26H'),
        'ends_at' => new DateTimeImmutable('0001-01-01 17:00:00', $prague), // pgsql-style value
    ];

    $schedule = $hydrator->fromData($row);

    Assert::same('0001-01-01 08:30:00', $schedule->startsAt->format('Y-m-d H:i:s'));
    Assert::same('0001-01-01 17:00:00', $schedule->endsAt->format('Y-m-d H:i:s'));

    // export is a plain time string, safe for any TIME column
    $data = $hydrator->toData($schedule);
    Assert::same('08:30:00', $data['starts_at']);

    // a DateInterval beyond the day scope cannot become #[Type\Time]
    Assert::exception(
        fn() => $hydrator->fromData(['starts_at' => new DateInterval('PT26H')] + $row),
        HydrationException::class,
        '~out of the day range.*DateInterval property~',
    );
});

test('Json uses the same day-scoped codec with camelCase fields', function () use ($prague): void {
    $hydrator = new Hydrator(Schedule::class, Json::class, $prague);

    $schedule = $hydrator->fromData([
        'id' => 1,
        'startsAt' => '08:30:00',
        'duration' => '120:00:00',
        'endsAt' => null,
    ]);

    Assert::same('08:30:00', $hydrator->toData($schedule)['startsAt']);
});

test('metadata errors', function (): void {
    Assert::exception(
        fn() => new Hydrator(BrokenTimeOnString::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~#\[Type\\\Time\] is only allowed on DateTimeImmutable~',
    );
    Assert::exception(
        fn() => new Hydrator(BrokenDateAndTime::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~cannot be combined~',
    );
});
