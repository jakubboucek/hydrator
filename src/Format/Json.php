<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JakubBoucek\Hydrator\Attribute\Fraction;
use JakubBoucek\Hydrator\Exception\ValueException;
use JakubBoucek\Hydrator\IdentityConverter;
use JakubBoucek\Hydrator\NameConverter;

/**
 * JSON representation (decoded payloads of APIs): property names map to
 * fields as-is (camelCase), date-times as RFC 3339 strings (a foreign offset
 * is recalculated into the application time zone), dates as `Y-m-d`, native
 * booleans and intervals as `HH:MM:SS` time strings (see Format — intervals
 * carry time-of-day semantics).
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

    public function exportDateTime(DateTimeImmutable $value, DateTimeZone $timeZone, ?Fraction $fraction = null): mixed
    {
        $value = $value->setTimezone($timeZone);

        if ($fraction === null) {
            return $value->format(DateTimeInterface::RFC3339);
        }

        // RFC 3339 with the fraction between seconds and offset
        return $value->format('Y-m-d\TH:i:s')
            . $this->fractionSuffix($value->format('u'), $fraction)
            . $value->format('P');
    }

    public function exportDate(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value->setTimezone($timeZone)->format('Y-m-d');
    }
}
