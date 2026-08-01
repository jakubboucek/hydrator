<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use DateInterval;
use JakubBoucek\Hydrator\IntervalValue;

/** Own custom type over a TIME column (full domain via DateInterval). */
class DurationValue implements IntervalValue
{
    final private function __construct(
        public readonly DateInterval $interval,
    ) {
    }

    public static function fromNative(DateInterval $value): static
    {
        return new static($value);
    }

    public function toNative(): ?DateInterval
    {
        return $this->interval;
    }

    public function toMinutes(): int
    {
        return ($this->interval->d * 24 + $this->interval->h) * 60 + $this->interval->i;
    }
}
