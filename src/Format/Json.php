<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use JakubBoucek\Hydrator\Exception\ValueException;
use JakubBoucek\Hydrator\IdentityConverter;
use JakubBoucek\Hydrator\NameConverter;

/**
 * JSON representation (decoded payloads of APIs): property names map to
 * fields as-is (camelCase), date-times as RFC 3339 strings (a foreign offset
 * is recalculated into the application time zone), dates as `Y-m-d`, native
 * booleans and intervals as ISO 8601 durations (`PT12M30S`, inverted with
 * a leading `-`).
 */
class Json extends Format
{
    protected function createNameConverter(): NameConverter
    {
        return new IdentityConverter();
    }

    public function importBool(mixed $value): bool
    {
        return is_bool($value)
            ? $value
            : throw new ValueException('Expected bool, got ' . get_debug_type($value) . '.');
    }

    public function exportBool(bool $value): mixed
    {
        return $value;
    }

    public function exportDateTime(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value->setTimezone($timeZone)->format(DateTimeInterface::RFC3339);
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

        if (is_string($value)) {
            $inverted = str_starts_with($value, '-');
            $spec = $inverted ? substr($value, 1) : $value;

            // DateInterval cannot parse fractional seconds, extract them first
            $fraction = 0.0;
            $spec = (string) preg_replace_callback(
                '~(\d+)\.(\d+)S~',
                function (array $match) use (&$fraction): string {
                    $fraction = (float) ('0.' . $match[2]);
                    return $match[1] . 'S';
                },
                $spec,
            );

            try {
                $interval = new DateInterval($spec);
            } catch (Exception $e) {
                throw new ValueException("Invalid ISO 8601 duration '{$value}'.", previous: $e);
            }

            $interval->f = $fraction;
            $interval->invert = (int) $inverted;
            return $interval;
        }

        throw new ValueException(
            'Expected ISO 8601 duration string or DateInterval, got ' . get_debug_type($value) . '.',
        );
    }

    public function exportInterval(DateInterval $value): mixed
    {
        $date = ($value->y !== 0 ? $value->y . 'Y' : '')
            . ($value->m !== 0 ? $value->m . 'M' : '')
            . ($value->d !== 0 ? $value->d . 'D' : '');

        $seconds = '';
        if ($value->s !== 0 || $value->f > 0.0) {
            $seconds = $value->f > 0.0
                ? rtrim(rtrim(sprintf('%.6F', $value->s + $value->f), '0'), '.') . 'S'
                : $value->s . 'S';
        }
        $time = ($value->h !== 0 ? $value->h . 'H' : '')
            . ($value->i !== 0 ? $value->i . 'M' : '')
            . $seconds;

        $spec = 'P' . $date . ($time !== '' ? 'T' . $time : '');
        if ($spec === 'P') {
            $spec = 'PT0S';
        }

        return ($value->invert === 1 ? '-' : '') . $spec;
    }
}
