<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Struct;

use JakubBoucek\Hydrator\Exception\ValueException;
use JakubBoucek\Hydrator\Struct;
use JsonException;

/**
 * Default Struct implementation with declared-fields semantics: subclasses
 * declare public properties (they must accept null — nullable or untyped)
 * and get the whole JSON plumbing for free.
 *
 * Known limitations, by design — for lossless free-form data use
 * DynamicStruct instead:
 *
 * - fromArray() fills only the declared properties; unknown keys in the
 *   data are silently dropped (lossy on a hydrate-extract roundtrip).
 * - toArray() filters out null values, so an explicit null is
 *   indistinguishable from an absent key.
 *
 * Emptiness: a struct whose toArray() is empty renders toJson() as null —
 * that is how an empty struct becomes NULL in the database.
 *
 * @phpstan-consistent-constructor Subclasses must stay constructible
 *   without arguments (the Struct contract).
 */
abstract class BaseStruct implements Struct
{
    private const int JsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function fromJson(?string $json): static
    {
        if ($json === null) {
            return new static();
        }

        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ValueException('Invalid JSON for ' . static::class . ": {$e->getMessage()}", previous: $e);
        }

        if (!is_array($data)) {
            throw new ValueException(
                'Expected JSON object or array for ' . static::class . ', got ' . get_debug_type($data) . '.',
            );
        }

        return static::fromArray($data);
    }

    public function toJson(): ?string
    {
        $data = $this->toArray();

        return $data === [] ? null : json_encode($data, self::JsonFlags);
    }

    public static function fromArray(array $data): static
    {
        $self = new static();
        foreach (get_class_vars(static::class) as $key => $default) {
            $self->$key = $data[$key] ?? null;
        }

        return $self;
    }

    public function toArray(): array
    {
        return array_filter(get_object_vars($this), static fn(mixed $value): bool => $value !== null);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }
}
