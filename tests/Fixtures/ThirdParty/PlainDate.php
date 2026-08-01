<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures\ThirdParty;

use DateTimeImmutable;

/**
 * Simulates a foreign date library type NOT derived from DateTimeImmutable
 * (the Brick\DateTime\LocalDate situation) — needs a TypeAdapter.
 */
final class PlainDate
{
    private function __construct(
        private readonly string $isoDate,
    ) {
    }

    public static function fromDateTime(DateTimeImmutable $dateTime): self
    {
        return new self($dateTime->format('Y-m-d'));
    }

    public function toDateTime(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->isoDate . ' 00:00:00');
    }

    public function toIso(): string
    {
        return $this->isoDate;
    }
}
