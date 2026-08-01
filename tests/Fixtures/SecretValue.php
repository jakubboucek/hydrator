<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Value\StringValue;

/** Custom type with inner nullness: toNative() may return null. */
class SecretValue implements StringValue
{
    public ?string $secret = null;

    public static function fromNative(string $value): static
    {
        $self = new static();
        $self->secret = $value;

        return $self;
    }

    public function toNative(): ?string
    {
        return $this->secret;
    }
}
