<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Value\NativeType;
use JakubBoucek\Hydrator\Tests\Fixtures\ThirdParty\Ulid;
use JakubBoucek\Hydrator\Adapter\TypeAdapter;

class UlidAdapter implements TypeAdapter
{
    public function __construct(
        private readonly bool $uppercase = false,
    ) {
    }

    public static function provides(): array
    {
        return [
            Ulid::class => NativeType::String,
            'Acme\MissingLib\Ulid' => NativeType::String, // optional dependency — the class does not exist
        ];
    }

    public function import(mixed $value, string $targetClass): object
    {
        $string = (string) $value; // @phpstan-ignore cast.string

        return Ulid::fromString($this->uppercase ? strtoupper($string) : $string);
    }

    public function export(object $value): int|float|string|bool|null
    {
        assert($value instanceof Ulid);

        return $value->toString();
    }
}
