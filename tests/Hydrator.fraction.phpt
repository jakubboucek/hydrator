<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Json;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenFractionDigits;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenFractionOnString;
use JakubBoucek\Hydrator\Tests\Fixtures\Measurement;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Fixtures/Broken.php';

$prague = new DateTimeZone('Europe/Prague');

function measurementRow(): array
{
    return [
        'id' => 1,
        'measured_at' => '2026-07-29 10:30:00.123456',
        'processed_at' => '2026-07-29 10:31:00',
        'window_start' => '08:30:00.123456',
        'elapsed' => '90:00:01.5',
    ];
}

test('strict fraction rendering in the Mysql format', function () use ($prague): void {
    $hydrator = new Hydrator(Measurement::class, Mysql::class, $prague);
    $data = $hydrator->toData($hydrator->fromData(measurementRow()));

    Assert::same('2026-07-29 10:30:00.123456', $data['measured_at']);
    // omitZero: zero fraction disappears entirely
    Assert::same('2026-07-29 10:31:00', $data['processed_at']);
    // digits: 3 truncates (no rounding) and pads
    Assert::same('08:30:00.123', $data['window_start']);
    Assert::same('90:00:01.500000', $data['elapsed']);
});

test('fixed width: zero fraction is rendered unless omitZero', function () use ($prague): void {
    $hydrator = new Hydrator(Measurement::class, Mysql::class, $prague);
    $row = ['measured_at' => '2026-07-29 10:30:00', 'window_start' => '08:30:00'] + measurementRow();

    $data = $hydrator->toData($hydrator->fromData($row));

    Assert::same('2026-07-29 10:30:00.000000', $data['measured_at']);
    Assert::same('08:30:00.000', $data['window_start']);
});

test('NetteDatabase exports a finished string when Fraction is set', function () use ($prague): void {
    $hydrator = new Hydrator(Measurement::class, NetteDatabase::class, $prague);
    $data = $hydrator->toData($hydrator->fromData(measurementRow()));

    // no instance pass-through: Nette itself would drop the fraction
    Assert::same('2026-07-29 10:30:00.123456', $data['measured_at']);
    Assert::same('90:00:01.500000', $data['elapsed']);
});

test('format scoping: the interval fraction applies to databases only', function () use ($prague): void {
    $hydrator = new Hydrator(Measurement::class, Json::class, $prague);
    $row = [
        'id' => 1,
        'measuredAt' => '2026-07-29T10:30:00.123456+02:00',
        'processedAt' => null,
        'windowStart' => '08:30:00',
        'elapsed' => '90:00:01.5',
    ];

    $data = $hydrator->toData($hydrator->fromData($row));

    // unscoped Fraction(6) applies to Json too: RFC 3339 with the fraction
    Assert::same('2026-07-29T10:30:00.123456+02:00', $data['measuredAt']);
    // DatabaseFormat-scoped Fraction does not apply: default heuristic
    Assert::same('90:00:01.5', $data['elapsed']);
});

test('metadata errors', function (): void {
    Assert::exception(
        fn() => new Hydrator(BrokenFractionDigits::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~#\[Fraction\] digits must be between 0 and 6~',
    );
    Assert::exception(
        fn() => new Hydrator(BrokenFractionOnString::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~#\[Fraction\] is only allowed on date-time, time and interval~',
    );
});
