<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use DateInterval;
use DateTimeImmutable;
use JakubBoucek\Hydrator\Attribute\Fraction;
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Format\DatabaseFormat;

class Measurement implements Entity
{
    public int $id;

    /** DATETIME(6) column. */
    #[Fraction(6)]
    public DateTimeImmutable $measuredAt;

    #[Fraction(3, omitZero: true)]
    public ?DateTimeImmutable $processedAt;

    #[Type\Time]
    #[Fraction(3)]
    public DateTimeImmutable $windowStart;

    /** Strict fraction only towards databases, default heuristic elsewhere. */
    #[Fraction(6, formats: [DatabaseFormat::class])]
    public DateInterval $elapsed;
}
