<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use JakubBoucek\Hydrator\Exception\ValueException;

/** A custom value type backed by a string (see CustomValue). */
interface StringValue extends CustomValue
{
    /**
     * @throws ValueException
     */
    public static function fromNative(string $value): static;

    public function toNative(): ?string;
}
