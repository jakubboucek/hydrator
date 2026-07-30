<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Tests\Fixtures;

use JakubBoucek\Hydrator\Exception\ValueException;
use JakubBoucek\Hydrator\Struct;

/** Hand-rolled Struct implementation: exercises the bare contract, no base class. */
class ContactStruct implements Struct
{
    public ?string $email = null;
    public ?string $phone = null;

    public static function fromJson(?string $json): static
    {
        if ($json === null) {
            return new static();
        }

        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ValueException("Invalid JSON: {$e->getMessage()}", previous: $e);
        }

        return static::fromArray(is_array($data) ? $data : []);
    }

    public function toJson(): ?string
    {
        $data = $this->toArray();

        return $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function fromArray(array $data): static
    {
        $self = new static();
        $self->email = isset($data['email']) ? (string) $data['email'] : null;
        $self->phone = isset($data['phone']) ? (string) $data['phone'] : null;

        return $self;
    }

    public function toArray(): array
    {
        return array_filter(
            ['email' => $this->email, 'phone' => $this->phone],
            static fn(?string $value): bool => $value !== null,
        );
    }
}
