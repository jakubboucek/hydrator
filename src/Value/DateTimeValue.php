<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Value;

use DateTimeImmutable;
use JakubBoucek\Hydrator\Exception\ValueException;

/**
 * A custom value type backed by a date-time (see CustomValue). The
 * intermediate DateTimeImmutable passes the format codecs, so the custom
 * type stays format-blind: it receives an instance normalized into the
 * application time zone and the format decides the rendering ('Y-m-d
 * H:i:s' for Mysql, RFC 3339 for Json, instance pass-through for
 * NetteDatabase).
 */
interface DateTimeValue extends CustomValue
{
    /**
     * @throws ValueException
     */
    public static function fromNative(DateTimeImmutable $value): static;

    public function toNative(): ?DateTimeImmutable;
}
