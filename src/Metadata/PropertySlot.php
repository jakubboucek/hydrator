<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Metadata;

use JakubBoucek\Hydrator\Attribute\Fraction;
use ReflectionProperty;

/**
 * Precomputed per-property mapping plan for one (entity class, format) pair,
 * built once and reused for every processed row.
 *
 * @internal
 */
final readonly class PropertySlot
{
    public function __construct(
        public string $name,
        public string $fieldName,
        public ValueKind $kind,
        public string $type,
        public bool $enumBackedByInt,
        public bool $nullable,
        public bool $hasDefault,
        public bool $writable,
        public bool $readable,
        public ?Fraction $fraction,
        public ReflectionProperty $reflection,
    ) {
    }
}
