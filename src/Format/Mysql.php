<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
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

    public function importDateTime(mixed $value, DateTimeZone $timeZone): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($timeZone);
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($timeZone);
        }

        if (is_string($value)) {
            try {
                return new DateTimeImmutable($value, $timeZone)->setTimezone($timeZone);
            } catch (Exception $e) {
                throw new ValueException("Invalid date-time string '{$value}': {$e->getMessage()}", previous: $e);
            }
        }

        throw new ValueException(
            'Expected date-time string or DateTimeInterface, got ' . get_debug_type($value) . '.',
        );
    }

    public function exportDateTime(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value->setTimezone($timeZone)->format('Y-m-d H:i:s');
    }

    public function importDate(mixed $value, DateTimeZone $timeZone): DateTimeImmutable
    {
        return $this->importDateTime($value, $timeZone);
    }

    public function exportDate(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value->setTimezone($timeZone)->format('Y-m-d');
    }

    public function importInterval(mixed $value): DateInterval
    {
        if ($value instanceof DateInterval) {
            return $value;
        }

        if (is_string($value) && preg_match('~^(-?)(\d+):(\d{1,2}):(\d{1,2})(\.\d+)?$~D', $value, $m)) {
            $interval = new DateInterval("PT{$m[2]}H{$m[3]}M{$m[4]}S");
            $interval->f = isset($m[5]) ? (float) $m[5] : 0.0;
            $interval->invert = (int) (bool) $m[1];
            return $interval;
        }

        throw new ValueException(
            'Expected time string (HH:MM:SS) or DateInterval, got '
            . (is_string($value) ? "'{$value}'" : get_debug_type($value)) . '.',
        );
    }

    public function exportInterval(DateInterval $value): mixed
    {
        if ($value->y !== 0 || $value->m !== 0) {
            throw new ValueException('Cannot export an interval with a year or month part as time.');
        }

        $time = sprintf(
            '%s%02d:%02d:%02d',
            $value->invert === 1 ? '-' : '',
            $value->d * 24 + $value->h,
            $value->i,
            $value->s,
        );

        if ($value->f > 0.0) {
            $time .= substr(rtrim(rtrim(sprintf('%.6F', $value->f), '0'), '.'), 1);
        }

        return $time;
    }
}
