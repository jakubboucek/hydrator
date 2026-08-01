<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Value;

use JakubBoucek\Hydrator\Exception\ValueException;

/** A custom value type backed by a boolean (see CustomValue). */
interface BoolValue extends CustomValue
{
    /**
     * @throws ValueException
     */
    public static function fromNative(bool $value): static;

    public function toNative(): ?bool;
}
