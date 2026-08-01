<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Value\BoolValue;

/** Custom type over a bool intermediate (TINYINT(1) column). */
class FlagValue implements BoolValue
{
    public bool $on = false;

    public static function fromNative(bool $value): static
    {
        $self = new static();
        $self->on = $value;

        return $self;
    }

    public function toNative(): ?bool
    {
        return $this->on;
    }
}
