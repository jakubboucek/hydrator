<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use DateTimeImmutable;
use JakubBoucek\Hydrator\DateTimeValue;

/** Own custom type over a DATETIME column with domain behavior. */
class DeadlineValue implements DateTimeValue
{
    final private function __construct(
        public readonly DateTimeImmutable $at,
    ) {
    }

    public static function fromNative(DateTimeImmutable $value): static
    {
        return new static($value);
    }

    public function toNative(): ?DateTimeImmutable
    {
        return $this->at;
    }

    public function isOverdue(DateTimeImmutable $now): bool
    {
        return $now > $this->at;
    }
}
