<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use DateTimeZone;
use JakubBoucek\Hydrator\Format\Format;

/**
 * Application-wide entry point: holds the preferred format, the application
 * time zone and a cache of Hydrator instances per (entity class, format).
 * Register as a service in DI.
 */
class HydratorFactory
{
    /** @var array<string, Hydrator<covariant Entity>> */
    private array $hydrators = [];

    /**
     * @param class-string<Format> $format Preferred format used when no
     *   explicit format is requested.
     * @param DateTimeZone|null $timeZone Application time zone injected into
     *   every hydrated date-time; defaults to the PHP default time zone.
     * @param list<class-string<TypeAdapter>|TypeAdapter> $adapters Type
     *   adapters for foreign classes; order is binding — the first adapter
     *   declaring a class wins. Class-strings load lazily, instances suit
     *   adapters with dependencies.
     */
    public function __construct(
        private readonly string $format,
        private readonly ?DateTimeZone $timeZone = null,
        private readonly array $adapters = [],
    ) {
    }

    /**
     * Returns the (cached) Hydrator of the entity class, in the preferred
     * format or an explicitly requested one.
     *
     * @template T of Entity
     * @param class-string<T> $entityClass
     * @param class-string<Format>|null $format
     * @return Hydrator<T>
     */
    public function for(string $entityClass, ?string $format = null): Hydrator
    {
        $format ??= $this->format;

        /** @var Hydrator<T> */
        return $this->hydrators[$entityClass . '|' . $format]
            ??= new Hydrator($entityClass, $format, $this->timeZone, $this->adapters);
    }
}
