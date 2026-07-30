<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use JakubBoucek\Hydrator\Attribute\Fraction;
use JakubBoucek\Hydrator\Exception\ValueException;
use Throwable;

/**
 * Representation used by nette/database: the layer already converts values
 * on both sides (`newDateTime` → DateTimeImmutable, `convertBoolean` → bool,
 * TIME → DateInterval), so date-times, booleans and intervals pass through
 * as-is on export and instances are accepted on import. Date-time instances
 * are still normalized into the application time zone during hydration.
 *
 * fromDataSet() auto-detects the key field from Selection/ResultSet via
 * duck-typed getPrimary() — no hard dependency on nette/database.
 */
class NetteDatabase extends Mysql
{
    public function exportBool(bool $value): mixed
    {
        return $value;
    }

    public function exportDateTime(DateTimeImmutable $value, DateTimeZone $timeZone, ?Fraction $fraction = null): mixed
    {
        // with a Fraction attribute the instance pass-through would lose the
        // fraction in Nette's own 'Y-m-d H:i:s' formatting — export a
        // finished string instead
        return $fraction === null ? $value : parent::exportDateTime($value, $timeZone, $fraction);
    }

    public function exportDate(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value;
    }

    public function exportInterval(DateInterval $value, ?Fraction $fraction = null): mixed
    {
        // same as date-times: Nette formats DateInterval without fractions
        return $fraction === null ? $value : parent::exportInterval($value, $fraction);
    }

    /**
     * Nette normalizes MySQL TIME columns to DateInterval, so a day-scoped
     * `#[Type\Time]` property must accept it — within the day range only;
     * beyond it (sign, hours over 23) a DateInterval property is the right
     * mapping. Export stays the `H:i:s` string from the base: Nette writes
     * DateTimeInterface values by PHP type as a full `Y-m-d H:i:s` string,
     * which MySQL would only cast into TIME with a truncation note.
     */
    public function importTime(mixed $value, DateTimeZone $timeZone): DateTimeImmutable
    {
        if ($value instanceof DateInterval) {
            if ($value->invert === 1 || $value->y !== 0 || $value->m !== 0 || $value->d * 24 + $value->h > 23) {
                throw new ValueException(
                    'TIME value is out of the day range (00:00:00 <= x < 24:00:00),'
                    . ' use a DateInterval property for the full TIME domain.',
                );
            }

            $time = sprintf('%02d:%02d:%02d', $value->h, $value->i, $value->s);
            if ($value->f > 0.0) {
                $time .= substr(rtrim(rtrim(sprintf('%.6F', $value->f), '0'), '.'), 1);
            }

            return parent::importTime($time, $timeZone);
        }

        return parent::importTime($value, $timeZone);
    }

    public function detectKeyField(iterable $dataSet): ?string
    {
        if (is_object($dataSet) && method_exists($dataSet, 'getPrimary')) {
            try {
                $primary = $dataSet->getPrimary(false);
            } catch (Throwable) {
                return null;
            }
            // Composite (array) or missing primary key is not usable as a single key field
            return is_string($primary) ? $primary : null;
        }

        return null;
    }
}
