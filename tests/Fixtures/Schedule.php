<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use DateInterval;
use DateTimeImmutable;
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Entity;

class Schedule implements Entity
{
    public int $id;

    /** Day-scoped time of day. */
    #[Type\Time]
    public DateTimeImmutable $startsAt;

    /** Full TIME domain (sign, hours over 24). */
    public DateInterval $duration;

    #[Type\Time]
    public ?DateTimeImmutable $endsAt;
}
