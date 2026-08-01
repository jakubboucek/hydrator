<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use JakubBoucek\Hydrator\Exception\ValueException;

/** A custom value type backed by a float (see CustomValue). */
interface FloatValue extends CustomValue
{
    /**
     * @throws ValueException
     */
    public static function fromNative(float $value): static;

    public function toNative(): ?float;
}
