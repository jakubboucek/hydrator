<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures\ThirdParty;

/** Simulates a foreign library class that knows nothing about the hydrator. */
final class Ulid
{
    private function __construct(
        private readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
