<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use DateTimeImmutable;
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Entity;

/**
 * Legacy edge-case fixtures: naive typing that documents the traps
 * (zero dates, unsigned BIGINT saturation, DECIMAL precision in float).
 */
class EdgeCaseLossy implements Entity
{
    public int $id;

    #[Type\Date]
    public ?DateTimeImmutable $zeroDate;

    public ?DateTimeImmutable $zeroStamp;

    public int $bigUnsigned;

    public float $exactPrice;
}

/** Recommended legacy mappings: string properties keep values exact. */
class EdgeCaseSafe implements Entity
{
    public int $id;
    public ?string $zeroDate;
    public ?string $zeroStamp;
    public string $bigUnsigned;
    public string $exactPrice;
}

/** Non-nullable property over a zero-date column: the failure must be loud. */
class EdgeCaseStrict implements Entity
{
    public int $id;
    public DateTimeImmutable $zeroStamp;
}
