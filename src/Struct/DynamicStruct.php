<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Struct;

use AllowDynamicProperties;

/**
 * Free-form Struct (an stdClass analogy): no declared shape, fromArray()
 * keeps every key as a dynamic property — unlike the declared-fields
 * BaseStruct it does not drop unknown keys on a roundtrip.
 */
#[AllowDynamicProperties]
class DynamicStruct extends BaseStruct
{
    public static function fromArray(array $data): static
    {
        $self = new static();
        foreach ($data as $key => $value) {
            $self->$key = $value;
        }

        return $self;
    }

    public function __isset(string $name): bool
    {
        return property_exists($this, $name);
    }
}
