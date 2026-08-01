<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\NativeType;
use JakubBoucek\Hydrator\Tests\Fixtures\ThirdParty\PlainDate;
use JakubBoucek\Hydrator\TypeAdapter;

class PlainDateAdapter implements TypeAdapter
{
    public static function provides(): array
    {
        return [PlainDate::class => NativeType::DateTime];
    }

    public function import(mixed $value, string $targetClass): object
    {
        assert($value instanceof \DateTimeImmutable);

        return PlainDate::fromDateTime($value);
    }

    public function export(object $value): int|float|string|bool|\DateTimeImmutable|\DateInterval|null
    {
        assert($value instanceof PlainDate);

        return $value->toDateTime();
    }
}
