<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use JakubBoucek\Hydrator\Exception\ValueException;

/** A custom value type backed by an integer (see CustomValue). */
interface IntValue extends CustomValue
{
    /**
     * @throws ValueException
     */
    public static function fromNative(int $value): static;

    public function toNative(): ?int;
}
