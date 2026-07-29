<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Format;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use JakubBoucek\Hydrator\Exception\ValueException;
use JakubBoucek\Hydrator\NameConverter;
use JakubBoucek\Hydrator\SnakeCaseConverter;

/**
 * Definition of a data representation (database row, raw PDO row, …): the
 * field naming convention and the value codecs per logical type.
 *
 * Formats are stateless and identified by their class — the engine creates
 * the instance internally, the public Hydrator API works with class-strings
 * only. Customization is done by subclassing; thanks to `instanceof` scope
 * matching a subclass automatically inherits attribute scopes targeting its
 * parents. Value codecs throw ValueException on invalid values; the engine
 * wraps it with the entity/property context.
 */
abstract class Format implements FormatScope
{
    private NameConverter $converter;

    final public function __construct()
    {
    }

    /**
     * Field naming convention of the format; override to change.
     */
    protected function createNameConverter(): NameConverter
    {
        return new SnakeCaseConverter();
    }

    final public function fieldName(string $propertyName): string
    {
        return ($this->converter ??= $this->createNameConverter())->toFieldName($propertyName);
    }

    /**
     * Auto-detects the key field of a data set (e.g. a table primary key)
     * for Hydrator::fromDataSet() when no explicit key is requested.
     * Null means keyless (sequential keys).
     *
     * @param iterable<mixed> $dataSet
     */
    public function detectKeyField(iterable $dataSet): ?string
    {
        return null;
    }

    /**
     * @throws ValueException
     */
    abstract public function importBool(mixed $value): bool;

    abstract public function exportBool(bool $value): mixed;

    /**
     * Default import shared by the bundled formats: instances are normalized
     * into the application time zone, naive strings are interpreted in it and
     * strings carrying their own offset are recalculated into it.
     *
     * @throws ValueException
     */
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

    abstract public function exportDateTime(DateTimeImmutable $value, DateTimeZone $timeZone): mixed;

    /**
     * @throws ValueException
     */
    public function importDate(mixed $value, DateTimeZone $timeZone): DateTimeImmutable
    {
        return $this->importDateTime($value, $timeZone);
    }

    abstract public function exportDate(DateTimeImmutable $value, DateTimeZone $timeZone): mixed;

    /**
     * @throws ValueException
     */
    abstract public function importInterval(mixed $value): DateInterval;

    /**
     * @throws ValueException
     */
    abstract public function exportInterval(DateInterval $value): mixed;
}
