<?php

declare(strict_types=1);

use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Format\Json;
use JakubBoucek\Hydrator\Format\Mysql;
use JakubBoucek\Hydrator\Format\NetteDatabase;
use JakubBoucek\Hydrator\Hydrator;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenDateFormatOnInterval;
use JakubBoucek\Hydrator\Tests\Fixtures\BrokenFractionAndDateFormat;
use JakubBoucek\Hydrator\Tests\Fixtures\LogEntry;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Fixtures/Broken.php';

$prague = new DateTimeZone('Europe/Prague');

function logRow(): array
{
    return [
        'id' => 1,
        'logged_at' => '29.07.2026 10:30:00',
        'day' => '20260701',
        'tick' => '0830',
        'epoch' => '1753784200',
        'mixed_use' => '2026-07-29 10:30:00.123456',
    ];
}

test('the same pattern drives import and export (bidirectional roundtrip)', function () use ($prague): void {
    $hydrator = new Hydrator(LogEntry::class, Mysql::class, $prague);

    $entry = $hydrator->fromData(logRow());
    Assert::same('2026-07-29 10:30:00', $entry->loggedAt->format('Y-m-d H:i:s'));
    Assert::same('Europe/Prague', $entry->loggedAt->getTimezone()->getName());
    Assert::same('2026-07-01 00:00:00', $entry->day->format('Y-m-d H:i:s'));
    // lossy 'Hi' pattern: uncaptured seconds deterministically zeroed, pinned to year 1
    Assert::same('0001-01-01 08:30:00', $entry->tick->format('Y-m-d H:i:s'));
    Assert::same('1753784200', $entry->epoch->format('U'));

    $data = $hydrator->toData($entry);
    Assert::same('29.07.2026 10:30:00', $data['logged_at']);
    Assert::same('20260701', $data['day']);
    Assert::same('0830', $data['tick']);
    Assert::same('1753784200', $data['epoch']);
    // Mysql scope: #[Fraction(3)] applies, #[DateFormat] is Json-only
    Assert::same('2026-07-29 10:30:00.123', $data['mixed_use']);
});

test('per-format refinement: Fraction for DB, pattern for Json, on one property', function () use ($prague): void {
    $hydrator = new Hydrator(LogEntry::class, Json::class, $prague);
    $row = ['mixedUse' => '29.07.2026 10:30'] + array_combine(
        ['id', 'loggedAt', 'day', 'tick', 'epoch'],
        [1, '29.07.2026 10:30:00', '20260701', '0830', '1753784200'],
    );

    $entry = $hydrator->fromData($row);

    Assert::same('29.07.2026 10:30', $hydrator->toData($entry)['mixedUse']);
});

test('NetteDatabase with a pattern exports a finished string, instances still hydrate', function () use ($prague): void {
    $hydrator = new Hydrator(LogEntry::class, NetteDatabase::class, $prague);

    // instance input (Nette pass-through) goes through the codec, not the pattern
    $row = ['logged_at' => new DateTimeImmutable('2026-07-29 10:30:00', $prague)] + logRow();
    $entry = $hydrator->fromData($row);

    Assert::same('29.07.2026 10:30:00', $hydrator->toData($entry)['logged_at']);
});

test('a string not matching the pattern is rejected strictly', function () use ($prague): void {
    $hydrator = new Hydrator(LogEntry::class, Mysql::class, $prague);

    Assert::exception(
        fn() => $hydrator->fromData(['logged_at' => '2026-07-29 10:30:00'] + logRow()),
        HydrationException::class,
        "~does not match the date format 'd\.m\.Y H:i:s'~",
    );
    Assert::exception(
        fn() => $hydrator->fromData(['logged_at' => '29.07.2026 10:30:00 extra'] + logRow()),
        HydrationException::class,
        '~does not match the date format~',
    );
});

test('metadata errors', function (): void {
    Assert::exception(
        fn() => new Hydrator(BrokenDateFormatOnInterval::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~#\[DateFormat\] is only allowed on date-time, date and time~',
    );
    Assert::exception(
        fn() => new Hydrator(BrokenFractionAndDateFormat::class, Mysql::class)->fromData([]),
        MetadataException::class,
        '~cannot both apply~',
    );
});
