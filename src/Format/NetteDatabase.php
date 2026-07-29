<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
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

    public function exportDateTime(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value;
    }

    public function exportDate(DateTimeImmutable $value, DateTimeZone $timeZone): mixed
    {
        return $value;
    }

    public function exportInterval(DateInterval $value): mixed
    {
        return $value;
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
