<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

use DateTimeImmutable;
use DateTimeZone;
use JakubBoucek\Hydrator\Exception\ValueException;

/**
 * Raw MySQL/MariaDB representation (plain PDO/mysqli without a converting
 * database layer): date-times as `Y-m-d H:i:s` strings, dates as `Y-m-d`,
 * booleans as 0/1 and TIME columns as `HH:MM:SS` strings.
 *
 * Naive date-time strings are interpreted in the application time zone;
 * strings carrying their own offset are converted into it.
 */
class Mysql extends Format implements DatabaseFormat
{
    public function importBool(mixed $value): bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 0, $value === '0' => false,
            $value === 1, $value === '1' => true,
            default => throw new ValueException(
                'Expected bool-like value (0/1), got ' . get_debug_type($value) . '.',
            ),
        };
    }

    public function exportBool(bool $value): mixed
    {
        return $value ? 1 : 0;
    }

    public function exportDateTime(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value->setTimezone($timeZone)->format('Y-m-d H:i:s');
    }

    public function exportDate(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value->setTimezone($timeZone)->format('Y-m-d');
    }

}
