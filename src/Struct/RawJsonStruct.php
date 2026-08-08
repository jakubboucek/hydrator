<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Struct;

use InvalidArgumentException;
use JakubBoucek\Hydrator\Exception\ValueException;
use JakubBoucek\Hydrator\Struct;
use JsonException;

/**
 * Verbatim Struct over a foreign JSON document: the serialized string is
 * the single source of truth, stored and returned byte-exact — toJson()
 * never re-encodes, so number representation, escaping, key order and even
 * duplicate keys survive a load-store roundtrip untouched. Intended for
 * external payloads where the struct maps only the fields of interest and
 * everything else must stay intact.
 *
 * The interface is deliberately read-only; the decoded document is a lazy
 * read-only cache built on first read. A future write path goes toArray()
 * → modify → fromArray() (a new instance, a new string).
 *
 * The root must be a JSON object (`{`); fromArray() mirrors that by
 * rejecting lists. Emptiness is presence of the document, not its content:
 * a NULL column hydrates into an empty instance and renders back as NULL,
 * while a present empty object ('{}') is a value and roundtrips verbatim —
 * unlike declared-fields structs, this class never normalizes a present
 * document to NULL.
 *
 * Subclasses read the document through the protected API and expose their
 * own typed public getters. The strict get*() methods are non-null and
 * throw ValueException on a missing or null field; the tryGet*() twins
 * return null for missing-or-null (the `$data['a'][0] ?? null` analogy)
 * but still throw on a present value of the wrong type. A nested field is
 * addressed by an array path: `$this->getString(['user', 'address', 'city'])`.
 *
 * @phpstan-consistent-constructor Subclasses must stay constructible
 *   without arguments (the Struct contract).
 */
abstract class RawJsonStruct implements Struct
{
    private const int JsonFlags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** RFC 8259 insignificant whitespace allowed before the root token. */
    private const string JsonWhitespace = " \t\n\r";

    /** The document as it arrived — the single source of truth, never re-encoded. */
    private ?string $json = null;

    /**
     * Lazy read-only cache of the decoded document. Never written back;
     * the string above stays authoritative.
     *
     * @var array<array-key, mixed>|null
     */
    private ?array $decoded = null;

    public static function fromJson(?string $json): static
    {
        if ($json === null) {
            return new static();
        }

        if (!json_validate($json)) {
            throw new ValueException('Invalid JSON for ' . static::class . ': ' . json_last_error_msg());
        }

        if (!str_starts_with(ltrim($json, self::JsonWhitespace), '{')) {
            throw new ValueException('Expected JSON object root for ' . static::class . '.');
        }

        $self = new static();
        $self->json = $json;

        return $self;
    }

    public function toJson(): ?string
    {
        return $this->json;
    }

    public static function fromArray(array $data): static
    {
        if ($data === []) {
            return new static();
        }

        if (array_is_list($data)) {
            throw new ValueException('Expected associative array (a JSON object) for ' . static::class . ', list given.');
        }

        $self = new static();

        try {
            $self->json = json_encode($data, self::JsonFlags);
        } catch (JsonException $e) {
            throw new ValueException('Unserializable data for ' . static::class . ": {$e->getMessage()}", previous: $e);
        }

        return $self;
    }

    public function toArray(): array
    {
        return $this->getDecoded();
    }

    public function isEmpty(): bool
    {
        return $this->json === null;
    }

    /**
     * Existence test with array_key_exists() semantics: an explicit JSON
     * null is a present field — the only way to tell missing from null.
     *
     * @param string|int|array<string|int> $key
     */
    protected function hasValue(string|int|array $key): bool
    {
        [$exists] = $this->seek($key);

        return $exists;
    }

    /**
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field is missing or null.
     */
    protected function getValue(string|int|array $key): mixed
    {
        [$exists, $value] = $this->seek($key);

        if (!$exists) {
            throw new ValueException('Missing field ' . self::pathLabel($key) . ' in ' . static::class . '.');
        }

        if ($value === null) {
            throw new ValueException('Field ' . self::pathLabel($key) . ' in ' . static::class . ' is null.');
        }

        return $value;
    }

    /**
     * Missing-tolerant read: a missing field and an explicit JSON null
     * both return null.
     *
     * @param string|int|array<string|int> $key
     */
    protected function tryGetValue(string|int|array $key): mixed
    {
        [, $value] = $this->seek($key);

        return $value;
    }

    /**
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field is missing, null or not a string.
     */
    protected function getString(string|int|array $key): string
    {
        $value = $this->getValue($key);

        return is_string($value) ? $value : throw self::typeError($key, 'string', $value);
    }

    /**
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field holds a non-null value that is not a string.
     */
    protected function tryGetString(string|int|array $key): ?string
    {
        $value = $this->tryGetValue($key);

        return $value === null || is_string($value) ? $value : throw self::typeError($key, 'string', $value);
    }

    /**
     * Strictly int: a JSON fraction (3.0) is rejected, truncation would
     * be a silent cast.
     *
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field is missing, null or not an int.
     */
    protected function getInt(string|int|array $key): int
    {
        $value = $this->getValue($key);

        return is_int($value) ? $value : throw self::typeError($key, 'int', $value);
    }

    /**
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field holds a non-null value that is not an int.
     */
    protected function tryGetInt(string|int|array $key): ?int
    {
        $value = $this->tryGetValue($key);

        return $value === null || is_int($value) ? $value : throw self::typeError($key, 'int', $value);
    }

    /**
     * Accepts an int as well — JSON has a single number type.
     *
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field is missing, null or not a number.
     */
    protected function getFloat(string|int|array $key): float
    {
        $value = $this->getValue($key);

        return match (true) {
            is_float($value) => $value,
            is_int($value) => (float) $value,
            default => throw self::typeError($key, 'float', $value),
        };
    }

    /**
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field holds a non-null value that is not a number.
     */
    protected function tryGetFloat(string|int|array $key): ?float
    {
        $value = $this->tryGetValue($key);

        return match (true) {
            $value === null, is_float($value) => $value,
            is_int($value) => (float) $value,
            default => throw self::typeError($key, 'float', $value),
        };
    }

    /**
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field is missing, null or not a bool.
     */
    protected function getBool(string|int|array $key): bool
    {
        $value = $this->getValue($key);

        return is_bool($value) ? $value : throw self::typeError($key, 'bool', $value);
    }

    /**
     * @param string|int|array<string|int> $key
     * @throws ValueException When the field holds a non-null value that is not a bool.
     */
    protected function tryGetBool(string|int|array $key): ?bool
    {
        $value = $this->tryGetValue($key);

        return $value === null || is_bool($value) ? $value : throw self::typeError($key, 'bool', $value);
    }

    /**
     * @param string|int|array<string|int> $key
     * @return array<array-key, mixed>
     * @throws ValueException When the field is missing, null or not an array.
     */
    protected function getArray(string|int|array $key): array
    {
        $value = $this->getValue($key);

        return is_array($value) ? $value : throw self::typeError($key, 'array', $value);
    }

    /**
     * @param string|int|array<string|int> $key
     * @return array<array-key, mixed>|null
     * @throws ValueException When the field holds a non-null value that is not an array.
     */
    protected function tryGetArray(string|int|array $key): ?array
    {
        $value = $this->tryGetValue($key);

        return $value === null || is_array($value) ? $value : throw self::typeError($key, 'array', $value);
    }

    /**
     * @param string|int|array<string|int> $key
     * @return array{bool, mixed} [exists, value]
     */
    private function seek(string|int|array $key): array
    {
        $path = is_array($key) ? $key : [$key];

        if ($path === []) {
            throw new InvalidArgumentException('Field path for ' . static::class . ' must not be empty.');
        }

        $value = $this->getDecoded();

        foreach ($path as $step) {
            if (!is_array($value) || !array_key_exists($step, $value)) {
                return [false, null];
            }

            $value = $value[$step];
        }

        return [true, $value];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function getDecoded(): array
    {
        if ($this->decoded !== null) {
            return $this->decoded;
        }

        if ($this->json === null) {
            return [];
        }

        $data = json_decode($this->json, associative: true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data)); // object root enforced at construction

        return $this->decoded = $data;
    }

    /**
     * @param string|int|array<string|int> $key
     */
    private static function typeError(string|int|array $key, string $expected, mixed $value): ValueException
    {
        return new ValueException(
            'Field ' . self::pathLabel($key) . ' in ' . static::class
            . " expected {$expected}, got " . get_debug_type($value) . '.',
        );
    }

    /**
     * @param string|int|array<string|int> $key
     */
    private static function pathLabel(string|int|array $key): string
    {
        return "'" . implode('.', is_array($key) ? $key : [$key]) . "'";
    }
}
