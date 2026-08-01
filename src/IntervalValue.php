<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use DateInterval;
use JakubBoucek\Hydrator\Exception\ValueException;

/**
 * A custom value type backed by a time interval (see CustomValue). The
 * intermediate DateInterval passes the format codecs — full TIME domain,
 * `HH:MM:SS` strings in string formats, instance pass-through for
 * NetteDatabase.
 */
interface IntervalValue extends CustomValue
{
    /**
     * @throws ValueException
     */
    public static function fromNative(DateInterval $value): static;

    public function toNative(): ?DateInterval;
}
