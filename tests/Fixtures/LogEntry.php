<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use DateTimeImmutable;
use JakubBoucek\Hydrator\Attribute\DateFormat;
use JakubBoucek\Hydrator\Attribute\Fraction;
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Format\DatabaseFormat;
use JakubBoucek\Hydrator\Format\Json;

class LogEntry implements Entity
{
    public int $id;

    #[DateFormat('d.m.Y H:i:s')]
    public DateTimeImmutable $loggedAt;

    #[Type\Date]
    #[DateFormat('Ymd')]
    public DateTimeImmutable $day;

    /** Lossy pattern: seconds are not captured. */
    #[Type\Time]
    #[DateFormat('Hi')]
    public DateTimeImmutable $tick;

    #[DateFormat('U')]
    public DateTimeImmutable $epoch;

    /** Different refinement per format: strict fraction for DB, pattern for API. */
    #[Fraction(3, formats: [DatabaseFormat::class])]
    #[DateFormat('d.m.Y H:i', formats: [Json::class])]
    public DateTimeImmutable $mixedUse;
}
