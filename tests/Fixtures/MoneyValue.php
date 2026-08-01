<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Value\IntValue;

/** Own custom type: money as minor units in an INT column. */
class MoneyValue implements IntValue
{
    final private function __construct(
        public readonly int $cents,
    ) {
    }

    public static function fromNative(int $value): static
    {
        return new static($value);
    }

    public function toNative(): ?int
    {
        return $this->cents;
    }

    public function format(): string
    {
        return number_format($this->cents / 100, 2, '.', '');
    }
}
