<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use BackedEnum;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Error;
use Generator;
use JsonException;
use JakubBoucek\Hydrator\Adapter\TypeAdapter;
use JakubBoucek\Hydrator\Attribute\DateFormat;
use JakubBoucek\Hydrator\Attribute\FormatScoped;
use JakubBoucek\Hydrator\Attribute\Fraction;
use JakubBoucek\Hydrator\Attribute\Name;
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Exception\HydrationException;
use JakubBoucek\Hydrator\Exception\ExtractionException;
use JakubBoucek\Hydrator\Exception\InvalidEntityException;
use JakubBoucek\Hydrator\Exception\MetadataException;
use JakubBoucek\Hydrator\Exception\ValueException;
use JakubBoucek\Hydrator\Format\Format;
use JakubBoucek\Hydrator\Format\FormatScope;
use JakubBoucek\Hydrator\Metadata\CustomSpec;
use JakubBoucek\Hydrator\Metadata\PropertySlot;
use JakubBoucek\Hydrator\Metadata\ValueKind;
use JakubBoucek\Hydrator\Value\BoolValue;
use JakubBoucek\Hydrator\Value\CustomValue;
use JakubBoucek\Hydrator\Value\DateTimeValue;
use JakubBoucek\Hydrator\Value\FloatValue;
use JakubBoucek\Hydrator\Value\IntervalValue;
use JakubBoucek\Hydrator\Value\IntValue;
use JakubBoucek\Hydrator\Value\NativeType;
use JakubBoucek\Hydrator\Value\StringValue;
use PropertyHookType;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;
use Traversable;
use ValueError;

/**
 * Bidirectional mapper between one entity class and its data representation
 * in one format. Property metadata (field names, logical kinds, reflections)
 * is built once and cached; per-row work is a plain loop. The library never
 * caches data or entities — that is the application's domain.
 *
 * Field handling contract: a field missing in data for a writable property
 * throws a HydrationException; extra fields in data with no matching
 * property are silently ignored. Fields of non-writable properties
 * (readonly, private(set), virtual get-only) are never required.
 *
 * The class is deliberately not final (internals stay private, so a
 * subclass can only wrap or replace the public API — an escape hatch for
 * fakes in tests or behavior fixes), see the final policy in CLAUDE.md.
 *
 * @template T of Entity
 */
class Hydrator
{
    private readonly Format $format;

    private readonly DateTimeZone $timeZone;

    /** @var list<class-string<TypeAdapter>|TypeAdapter> */
    private readonly array $adapters;

    /**
     * Adapter capability map, built lazily on the first non-native class
     * (a future cache layer can replace the build with a plain data load).
     *
     * @var array<string, array{ValueKind, class-string<TypeAdapter>}>
     */
    private array $adapterMap;

    /** @var array<class-string<TypeAdapter>, TypeAdapter> */
    private array $adapterInstances = [];

    /** @var array<string, PropertySlot> */
    private array $slots;

    /** @var array<string, true> */
    private array $expectedFields;

    /**
     * @param class-string<T> $entityClass
     * @param class-string<Format> $format
     * @param DateTimeZone|null $timeZone Application time zone injected into
     *   every hydrated date-time; defaults to the PHP default time zone.
     * @param list<class-string<TypeAdapter>|TypeAdapter> $adapters Type
     *   adapters for foreign classes; order is binding — the first adapter
     *   declaring a class wins. Class-strings load lazily.
     */
    public function __construct(
        private readonly string $entityClass,
        string $format,
        ?DateTimeZone $timeZone = null,
        array $adapters = [],
    ) {
        if (!class_exists($entityClass)) {
            throw new MetadataException("Entity class '{$entityClass}' does not exist.");
        }
        if (!is_subclass_of($entityClass, Entity::class)) {
            throw new MetadataException(
                "Entity class '{$entityClass}' must implement the " . Entity::class . ' marker interface.',
            );
        }
        if (!is_a($format, Format::class, true)) {
            throw new MetadataException("Format '{$format}' must extend " . Format::class . '.');
        }

        $this->format = new $format();
        $this->timeZone = $timeZone ?? new DateTimeZone(date_default_timezone_get());
        $this->adapters = $adapters;
    }

    /**
     * Hydrates one data record into a new (or provided) entity instance.
     *
     * Every writable property requires its field in data — a missing field
     * throws a HydrationException. Extra fields with no matching property
     * are silently ignored. Both rules have per-call switches:
     *
     * - `allowPartial`: missing fields are tolerated and the corresponding
     *   properties stay uninitialized — the input mirror of the
     *   partial-update extraction (a sparse SELECT hydrates a sparse
     *   entity). Combined with `$into` it acts as a merge: absent fields
     *   keep the target's current values. Beware: the strict default
     *   catches column-name typos, allowPartial does not — pair it with
     *   `rejectUnknown` to keep that protection.
     * - `rejectUnknown`: a data key that maps to no entity property throws
     *   (expected keys are the fields of all mapped properties, including
     *   non-writable ones, which extraction produces).
     *
     * @param iterable<string, mixed> $data
     * @param T|null $into Existing instance to re-hydrate into.
     * @return T
     */
    public function fromData(
        iterable $data,
        ?Entity $into = null,
        bool $allowPartial = false,
        bool $rejectUnknown = false,
    ): Entity {
        $row = is_array($data) ? $data : iterator_to_array($data);

        if ($into !== null && !$into instanceof $this->entityClass) {
            throw new InvalidEntityException(
                "Target instance must be a '{$this->entityClass}', got '" . $into::class . "'.",
            );
        }

        if ($rejectUnknown) {
            $unknown = array_diff_key($row, $this->expectedFields());
            if ($unknown !== []) {
                throw new HydrationException(
                    "Unknown fields '" . implode("', '", array_keys($unknown))
                    . "' in data for {$this->entityClass}.",
                );
            }
        }

        /** @var T $entity */
        $entity = $into ?? new $this->entityClass();

        foreach ($this->slots() as $name => $slot) {
            if (!$slot->writable) {
                continue;
            }

            if (!array_key_exists($slot->fieldName, $row)) {
                if ($allowPartial) {
                    continue;
                }
                throw new HydrationException(
                    "Missing field '{$slot->fieldName}' in data for property {$this->entityClass}::\${$name}.",
                );
            }

            $value = $row[$slot->fieldName];

            // legacy zero dates hydrate as null (parity with nette/database),
            // loudly — the data cannot be represented by a DateTimeImmutable
            if (($slot->kind === ValueKind::DateTime || $slot->kind === ValueKind::Date)
                && is_string($value)
                && str_starts_with($value, '0000-00')
            ) {
                trigger_error(
                    "Zero date '{$value}' in field '{$slot->fieldName}' is not supported,"
                    . " hydrating property {$this->entityClass}::\${$name} as null.",
                    E_USER_WARNING,
                );
                $value = null;
            }

            if ($value === null && $slot->kind === ValueKind::Struct) {
                // a struct always exists in the hydrated entity (NULL column
                // ≡ empty struct), so it is writable at any time
                /** @var class-string<Struct> $structClass */
                $structClass = $slot->type;
                $entity->$name = $structClass::fromJson(null);
                continue;
            }

            if ($value === null) {
                if ($slot->nullable) {
                    $entity->$name = null;
                    continue;
                }
                if ($slot->hasDefault) {
                    continue;
                }
                throw new HydrationException(
                    "Field '{$slot->fieldName}' is null but property {$this->entityClass}::\${$name} is not nullable.",
                );
            }

            try {
                $entity->$name = match ($slot->kind) {
                    ValueKind::Int => (int) $value, // @phpstan-ignore cast.int
                    ValueKind::Float => (float) $value, // @phpstan-ignore cast.double
                    ValueKind::String => $this->importString($value),
                    ValueKind::Bool => $this->format->importBool($value),
                    ValueKind::DateTime, ValueKind::Date, ValueKind::Time
                        => $this->asDeclaredDateTime($this->importTemporal($slot, $value), $slot->type),
                    ValueKind::Interval => $this->format->importInterval($value),
                    ValueKind::Enum => $this->importEnum($value, $slot),
                    ValueKind::Struct => $this->importStruct($value, $slot),
                    ValueKind::Custom => $this->importCustom($value, $slot),
                    ValueKind::Mixed => $value,
                };
            } catch (ValueException | ValueError | JsonException $e) {
                throw new HydrationException(
                    "Cannot hydrate property {$this->entityClass}::\${$name} from field"
                    . " '{$slot->fieldName}': {$e->getMessage()}",
                    previous: $e,
                );
            }
        }

        return $entity;
    }

    /**
     * Hydrates a stream of data records. Stream-first: returns a lazy,
     * single-pass EntitySet, nothing is buffered and the source is not
     * touched until the first consumption. Keys: the data set's own keys
     * pass through transparently (a Nette Selection keys its iteration by
     * the primary key, a plain list yields sequential keys), or `$keyBy`
     * re-keys the stream by an entity property — the key is read from the
     * hydrated entity, so it is properly typed and the caller never deals
     * with the format's naming convention. The keyBy property must be a
     * hydrated (writable), non-nullable int or string property.
     *
     * @param iterable<int|string, mixed> $dataSet
     * @return EntitySet<T>
     */
    public function fromDataSet(
        iterable $dataSet,
        ?string $keyBy = null,
        bool $allowPartial = false,
        bool $rejectUnknown = false,
    ): EntitySet {
        $keySlot = null;
        if ($keyBy !== null) {
            $keySlot = $this->slots()[$keyBy] ?? throw new MetadataException(
                "Unknown keyBy property '{$keyBy}', entity {$this->entityClass} has no such mapped property.",
            );
            if ($keySlot->kind !== ValueKind::Int && $keySlot->kind !== ValueKind::String) {
                throw new MetadataException(
                    "The keyBy property {$this->entityClass}::\${$keyBy} must be typed int or string"
                    . ' to serve as an array key.',
                );
            }
            if (!$keySlot->writable) {
                throw new MetadataException(
                    "The keyBy property {$this->entityClass}::\${$keyBy} is never hydrated"
                    . ' (readonly, private(set) or virtual), so it cannot key the stream.',
                );
            }
            if ($keySlot->nullable) {
                throw new MetadataException(
                    "The keyBy property {$this->entityClass}::\${$keyBy} must not be nullable,"
                    . ' null is not usable as an array key.',
                );
            }
        }

        return new EntitySet($this->hydrateDataSet($dataSet, $keySlot, $allowPartial, $rejectUnknown));
    }

    /**
     * @param iterable<int|string, mixed> $dataSet
     * @return Generator<int|string, T>
     */
    private function hydrateDataSet(
        iterable $dataSet,
        ?PropertySlot $keySlot,
        bool $allowPartial,
        bool $rejectUnknown,
    ): Generator {
        foreach ($dataSet as $key => $data) {
            if (!is_iterable($data)) {
                throw new HydrationException(
                    'Data set item must be an array or Traversable, got ' . get_debug_type($data) . '.',
                );
            }
            /** @var iterable<string, mixed> $data */
            $entity = $this->fromData($data, allowPartial: $allowPartial, rejectUnknown: $rejectUnknown);

            if ($keySlot !== null) {
                try {
                    /** @var int|string $key */
                    $key = $entity->{$keySlot->name};
                } catch (Error $e) {
                    // reflection is consulted only on the error path — the
                    // hot path stays a plain property read (try is free)
                    if (!$keySlot->reflection->isInitialized($entity)) {
                        throw new HydrationException(
                            "Cannot key the stream by {$this->entityClass}::\${$keySlot->name}:"
                            . " the field '{$keySlot->fieldName}' is missing in a data set item"
                            . ' (allowPartial left the property uninitialized).',
                            previous: $e,
                        );
                    }
                    throw $e; // a get hook failed on its own — not ours to relabel
                }
            }

            yield $key => $entity;
        }
    }

    /**
     * Extracts the entity to data. Partial-update semantics: only initialized
     * properties are extracted, an uninitialized property has no field in the
     * result. Date/interval representation is up to the format (instances for
     * NetteDatabase, strings for Mysql).
     *
     * @param T $entity
     * @return array<string, mixed>
     */
    public function toData(Entity $entity): array
    {
        $this->assertEntity($entity);

        $data = [];

        foreach ($this->slots() as $name => $slot) {
            if (!$slot->readable || !$slot->reflection->isInitialized($entity)) {
                continue;
            }

            $value = $entity->$name;

            if ($value === null) {
                $data[$slot->fieldName] = null;
                continue;
            }

            try {
                $data[$slot->fieldName] = match ($slot->kind) {
                    ValueKind::Bool => $this->format->exportBool($this->expectBool($value)),
                    ValueKind::DateTime, ValueKind::Date, ValueKind::Time
                        => $this->exportTemporal($slot, $this->expectDateTime($value)),
                    ValueKind::Interval => $this->format->exportInterval($this->expectInterval($value), $slot->fraction),
                    ValueKind::Enum => $this->expectEnum($value)->value,
                    ValueKind::Struct => $this->format->exportStruct($this->expectStruct($value)),
                    ValueKind::Custom => $this->exportCustom($value, $slot),
                    default => $value,
                };
            } catch (ValueException | JsonException $e) {
                throw new ExtractionException(
                    "Cannot extract property {$this->entityClass}::\${$name}: {$e->getMessage()}",
                    previous: $e,
                );
            }
        }

        return $data;
    }

    private function assertEntity(Entity $entity): void
    {
        if (!$entity instanceof $this->entityClass) {
            throw new InvalidEntityException(
                "Entity must be an instance of '{$this->entityClass}', got '" . $entity::class . "'.",
            );
        }
    }

    private function expectBool(mixed $value): bool
    {
        return is_bool($value)
            ? $value
            : throw new ValueException('Expected bool, got ' . get_debug_type($value) . '.');
    }

    private function expectDateTime(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable
            ? $value
            : throw new ValueException('Expected DateTimeImmutable, got ' . get_debug_type($value) . '.');
    }

    private function expectInterval(mixed $value): DateInterval
    {
        return $value instanceof DateInterval
            ? $value
            : throw new ValueException('Expected DateInterval, got ' . get_debug_type($value) . '.');
    }

    private function expectEnum(mixed $value): BackedEnum
    {
        return $value instanceof BackedEnum
            ? $value
            : throw new ValueException('Expected BackedEnum, got ' . get_debug_type($value) . '.');
    }

    private function expectStruct(mixed $value): Struct
    {
        return $value instanceof Struct
            ? $value
            : throw new ValueException('Expected Struct, got ' . get_debug_type($value) . '.');
    }

    private function importStruct(mixed $value, PropertySlot $slot): Struct
    {
        /** @var class-string<Struct> $structClass */
        $structClass = $slot->type;

        return $this->format->importStruct($value, $structClass);
    }

    private function importCustom(mixed $value, PropertySlot $slot): object
    {
        /** @var CustomSpec $spec */
        $spec = $slot->custom;

        // first level: the format codec of the intermediate native type,
        // with all its strictness — custom conversions stay format-blind
        $native = match ($spec->nativeKind) {
            ValueKind::Int => (int) $value, // @phpstan-ignore cast.int
            ValueKind::Float => (float) $value, // @phpstan-ignore cast.double
            ValueKind::Bool => $this->format->importBool($value),
            ValueKind::DateTime => $this->format->importDateTime($value, $this->timeZone),
            ValueKind::Interval => $this->format->importInterval($value),
            default => $this->importString($value),
        };

        /** @var class-string $targetClass */
        $targetClass = $slot->type;

        if ($spec->adapterClass === null) {
            /** @var class-string<StringValue> $targetClass */
            $object = $targetClass::fromNative($native); // @phpstan-ignore argument.type
        } else {
            $object = $this->adapter($spec->adapterClass)->import($native, $targetClass);
        }

        if (!$object instanceof $slot->type) {
            $source = $spec->adapterClass === null
                ? "{$slot->type}::fromNative()"
                : "Adapter '{$spec->adapterClass}'";
            throw new ValueException(
                "{$source} returned " . get_debug_type($object) . " instead of '{$slot->type}'.",
            );
        }

        return $object;
    }

    private function exportCustom(mixed $value, PropertySlot $slot): mixed
    {
        if (!$value instanceof $slot->type) {
            throw new ValueException("Expected '{$slot->type}', got " . get_debug_type($value) . '.');
        }

        /** @var CustomSpec $spec */
        $spec = $slot->custom;

        if ($spec->adapterClass === null) {
            if (!$value instanceof CustomValue) {
                throw new ValueException("Expected CustomValue, got " . get_debug_type($value) . '.');
            }
            $native = $value->toNative();
        } else {
            $native = $this->adapter($spec->adapterClass)->export($value);
        }

        // inner nullness: the value renders as a NULL field and collapses
        // to a plain null on the next hydration (documented policy)
        if ($native === null) {
            return null;
        }

        $source = $spec->adapterClass === null
            ? "{$slot->type}::toNative()"
            : "Adapter '{$spec->adapterClass}'";

        return match ($spec->nativeKind) {
            ValueKind::Int => is_int($native)
                ? $native
                : throw new ValueException("{$source} must return int, got " . get_debug_type($native) . '.'),
            ValueKind::Float => is_float($native)
                ? $native
                : throw new ValueException("{$source} must return float, got " . get_debug_type($native) . '.'),
            ValueKind::Bool => is_bool($native)
                ? $this->format->exportBool($native)
                : throw new ValueException("{$source} must return bool, got " . get_debug_type($native) . '.'),
            ValueKind::DateTime => $native instanceof DateTimeImmutable
                ? $this->format->exportDateTime($native, $this->timeZone)
                : throw new ValueException(
                    "{$source} must return DateTimeImmutable, got " . get_debug_type($native) . '.',
                ),
            ValueKind::Interval => $native instanceof DateInterval
                ? $this->format->exportInterval($native)
                : throw new ValueException(
                    "{$source} must return DateInterval, got " . get_debug_type($native) . '.',
                ),
            default => is_string($native)
                ? $native
                : throw new ValueException("{$source} must return string, got " . get_debug_type($native) . '.'),
        };
    }

    private function resolveCustomSpec(string $typeName, string $propertyName): CustomSpec
    {
        if (is_subclass_of($typeName, CustomValue::class)) {
            $matched = [];
            $interfaces = [
                StringValue::class => ValueKind::String,
                IntValue::class => ValueKind::Int,
                FloatValue::class => ValueKind::Float,
                BoolValue::class => ValueKind::Bool,
                DateTimeValue::class => ValueKind::DateTime,
                IntervalValue::class => ValueKind::Interval,
            ];
            foreach ($interfaces as $interface => $nativeKind) {
                if (is_subclass_of($typeName, $interface)) {
                    $matched[$interface] = $nativeKind;
                }
            }

            if (count($matched) !== 1) {
                throw new MetadataException(
                    $matched === []
                        ? "Class '{$typeName}' implements CustomValue directly — implement exactly one"
                            . ' typed interface (StringValue, IntValue, FloatValue, BoolValue,'
                            . ' DateTimeValue, IntervalValue)'
                            . " (property {$this->entityClass}::\${$propertyName})."
                        : "Class '{$typeName}' implements multiple typed CustomValue interfaces ("
                            . implode(', ', array_keys($matched))
                            . "), which is ambiguous (property {$this->entityClass}::\${$propertyName}).",
                );
            }

            return new CustomSpec(reset($matched), null);
        }

        [$nativeKind, $adapterClass] = $this->adapterMap()[$typeName];

        return new CustomSpec($nativeKind, $adapterClass);
    }

    /**
     * Builds the adapter capability map: pure data (a future cache layer can
     * load it instead of calling the static provides() methods). First-win:
     * the earliest registered adapter declaring a class keeps it.
     *
     * @return array<string, array{ValueKind, class-string<TypeAdapter>}>
     */
    private function adapterMap(): array
    {
        if (isset($this->adapterMap)) {
            return $this->adapterMap;
        }

        $map = [];
        $seen = [];

        foreach ($this->adapters as $adapter) {
            if ($adapter instanceof TypeAdapter) {
                $class = $adapter::class;
                if (isset($this->adapterInstances[$class]) && $this->adapterInstances[$class] !== $adapter) {
                    throw new MetadataException(
                        "Two different instances of adapter '{$class}' are registered, which is ambiguous.",
                    );
                }
                // a registered instance is the configured one — it wins over
                // a class-string registration of the same class
                $this->adapterInstances[$class] = $adapter;
            } else {
                if (!is_a($adapter, TypeAdapter::class, true)) {
                    throw new MetadataException(
                        'Adapter must be a class-string of ' . TypeAdapter::class
                        . " or its instance, got '{$adapter}'.",
                    );
                }
                $class = $adapter;
            }

            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;

            foreach ($class::provides() as $target => $nativeType) {
                $map[$target] ??= [
                    match ($nativeType) {
                        NativeType::String => ValueKind::String,
                        NativeType::Int => ValueKind::Int,
                        NativeType::Float => ValueKind::Float,
                        NativeType::Bool => ValueKind::Bool,
                        NativeType::DateTime => ValueKind::DateTime,
                        NativeType::Interval => ValueKind::Interval,
                    },
                    $class,
                ];
            }
        }

        return $this->adapterMap = $map;
    }

    /**
     * @param class-string<TypeAdapter> $class
     */
    private function adapter(string $class): TypeAdapter
    {
        return $this->adapterInstances[$class] ??= new $class();
    }

    private function importString(mixed $value): string
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new ValueException('Expected string-like value, got ' . get_debug_type($value) . '.');
    }

    private function importEnum(mixed $value, PropertySlot $slot): BackedEnum
    {
        if (!is_int($value) && !is_string($value)) {
            throw new ValueException('Expected enum backing value, got ' . get_debug_type($value) . '.');
        }

        /** @var class-string<BackedEnum> $enum */
        $enum = $slot->type;

        // DB layers may return backing values as strings (raw PDO), cast first
        return $enum::from($slot->enumBackedByInt ? (int) $value : (string) $value);
    }

    /**
     * @throws ValueException
     */
    private function importTemporal(PropertySlot $slot, mixed $value): DateTimeImmutable
    {
        // a custom pattern parses strings itself — same pattern as the export,
        // '!' zeroes the parts the pattern does not capture (deterministic
        // lossy roundtrip); instances still go through the format codec
        if ($slot->dateFormat !== null && is_string($value)) {
            $pattern = $slot->dateFormat->pattern;
            $parsed = DateTimeImmutable::createFromFormat('!' . $pattern, $value, $this->timeZone);
            if ($parsed === false || DateTimeImmutable::getLastErrors() !== false) {
                throw new ValueException("String '{$value}' does not match the date format '{$pattern}'.");
            }

            return $slot->kind === ValueKind::Time
                ? new DateTimeImmutable('0001-01-01 ' . $parsed->format('H:i:s.u'), $this->timeZone)
                : $parsed->setTimezone($this->timeZone);
        }

        return match ($slot->kind) {
            ValueKind::DateTime => $this->format->importDateTime($value, $this->timeZone),
            ValueKind::Date => $this->format->importDate($value, $this->timeZone),
            default => $this->format->importTime($value, $this->timeZone),
        };
    }

    private function exportTemporal(PropertySlot $slot, DateTimeImmutable $value): mixed
    {
        if ($slot->dateFormat !== null) {
            // Time kind keeps its wall clock, the others render in the app time zone
            $value = $slot->kind === ValueKind::Time ? $value : $value->setTimezone($this->timeZone);
            return $value->format($slot->dateFormat->pattern);
        }

        return match ($slot->kind) {
            ValueKind::DateTime => $this->format->exportDateTime($value, $this->timeZone, $slot->fraction),
            ValueKind::Date => $this->format->exportDate($value, $this->timeZone),
            default => $this->format->exportTime($value, $this->timeZone, $slot->fraction),
        };
    }

    private function asDeclaredDateTime(DateTimeImmutable $value, string $class): DateTimeImmutable
    {
        if ($class === DateTimeImmutable::class || $value instanceof $class) {
            return $value;
        }

        /** @var class-string<DateTimeImmutable> $class */
        return $class::createFromInterface($value);
    }

    /**
     * @return array<string, PropertySlot>
     */
    private function slots(): array
    {
        return $this->slots ??= $this->buildSlots();
    }

    /**
     * Fields of all mapped properties (including non-writable ones) —
     * the set of known keys for the rejectUnknown check.
     *
     * @return array<string, true>
     */
    private function expectedFields(): array
    {
        if (!isset($this->expectedFields)) {
            $fields = [];
            foreach ($this->slots() as $slot) {
                $fields[$slot->fieldName] = true;
            }
            $this->expectedFields = $fields;
        }

        return $this->expectedFields;
    }

    /**
     * @return array<string, PropertySlot>
     */
    private function buildSlots(): array
    {
        $slots = [];

        $properties = new ReflectionClass($this->entityClass)->getProperties(ReflectionProperty::IS_PUBLIC);
        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $slots[$property->getName()] = $this->buildSlot($property);
        }

        return $slots;
    }

    private function buildSlot(ReflectionProperty $property): PropertySlot
    {
        $name = $property->getName();
        $type = $property->getType();

        if ($type !== null && !$type instanceof ReflectionNamedType) {
            throw new MetadataException(
                "Only simple types are supported, property {$this->entityClass}::\${$name}"
                . ' has a union or intersection type.',
            );
        }

        $kind = $this->resolveKind($property, $type);

        if ($kind === ValueKind::Struct && $type !== null) {
            /** @var class-string<Struct> $structClass */
            $structClass = $type->getName();
            if (!new ReflectionClass($structClass)->isInstantiable()) {
                throw new MetadataException(
                    "Struct property {$this->entityClass}::\${$name} must be typed with"
                    . " a concrete Struct implementation, '{$structClass}' is not instantiable.",
                );
            }
            if (is_subclass_of($structClass, CustomValue::class)) {
                throw new MetadataException(
                    "Class '{$structClass}' implements both Struct and CustomValue,"
                    . " which is ambiguous (property {$this->entityClass}::\${$name}).",
                );
            }
        }

        $custom = $kind === ValueKind::Custom && $type !== null
            ? $this->resolveCustomSpec($type->getName(), $name)
            : null;

        // shadowing guard: an adapter must not claim a natively handled class
        if ($custom === null
            && $type !== null
            && !$type->isBuiltin()
            && $this->adapters !== []
            && isset($this->adapterMap()[$type->getName()])
        ) {
            throw new MetadataException(
                "Adapter '{$this->adapterMap()[$type->getName()][1]}' claims class"
                . " '{$type->getName()}', which the hydrator handles natively"
                . " (property {$this->entityClass}::\${$name}).",
            );
        }

        // Writable: no restricted setter, and a virtual property only with a set hook
        $writable = !$property->isReadOnly()
            && !$property->isProtectedSet()
            && !$property->isPrivateSet()
            && (!$property->isVirtual() || $property->hasHook(PropertyHookType::Set));
        $readable = !$property->isVirtual();

        $enumBackedByInt = false;
        if ($kind === ValueKind::Enum) {
            /** @var class-string<BackedEnum> $enumClass */
            $enumClass = $type?->getName() ?? '';
            $backingType = new ReflectionEnum($enumClass)->getBackingType();
            $enumBackedByInt = $backingType instanceof ReflectionNamedType && $backingType->getName() === 'int';
        }

        $fraction = $this->resolveScopedAttribute($property, Fraction::class);
        if ($fraction !== null) {
            if ($fraction->digits < 0 || $fraction->digits > 6) {
                throw new MetadataException(
                    '#[Fraction] digits must be between 0 and 6 (PHP microsecond precision),'
                    . " got {$fraction->digits} on {$this->entityClass}::\${$name}.",
                );
            }
            if (!in_array($kind, [ValueKind::DateTime, ValueKind::Time, ValueKind::Interval], true)) {
                throw new MetadataException(
                    '#[Fraction] is only allowed on date-time, time and interval properties,'
                    . " found on {$this->entityClass}::\${$name}.",
                );
            }
        }

        $dateFormat = $this->resolveScopedAttribute($property, DateFormat::class);
        if ($dateFormat !== null) {
            if ($dateFormat->pattern === '') {
                throw new MetadataException(
                    "#[DateFormat] pattern must not be empty on {$this->entityClass}::\${$name}.",
                );
            }
            if (!in_array($kind, [ValueKind::DateTime, ValueKind::Date, ValueKind::Time], true)) {
                throw new MetadataException(
                    '#[DateFormat] is only allowed on date-time, date and time properties'
                    . ' (DateInterval has no bidirectional format parser in PHP),'
                    . " found on {$this->entityClass}::\${$name}.",
                );
            }
            if ($fraction !== null) {
                throw new MetadataException(
                    '#[Fraction] and #[DateFormat] cannot both apply to'
                    . " {$this->entityClass}::\${$name} for format " . $this->format::class . '.',
                );
            }
        }

        return new PropertySlot(
            name: $name,
            fieldName: $this->resolveFieldName($property),
            kind: $kind,
            type: $type?->getName() ?? 'mixed',
            enumBackedByInt: $enumBackedByInt,
            nullable: $type?->allowsNull() ?? true,
            hasDefault: $property->hasDefaultValue(),
            writable: $writable,
            readable: $readable,
            fraction: $fraction,
            dateFormat: $dateFormat,
            custom: $custom,
            reflection: $property,
        );
    }

    private function resolveKind(ReflectionProperty $property, ?ReflectionNamedType $type): ValueKind
    {
        $name = $property->getName();
        $isDate = $property->getAttributes(Type\Date::class) !== [];
        $isTime = $property->getAttributes(Type\Time::class) !== [];

        if ($isDate && $isTime) {
            throw new MetadataException(
                '#[Type\Date] and #[Type\Time] cannot be combined'
                . " on {$this->entityClass}::\${$name}.",
            );
        }

        $kind = match (true) {
            $type === null, $type->getName() === 'mixed' => ValueKind::Mixed,
            $type->isBuiltin() => match ($type->getName()) {
                'int' => ValueKind::Int,
                'float' => ValueKind::Float,
                'string' => ValueKind::String,
                'bool' => ValueKind::Bool,
                default => throw new MetadataException(
                    "Unsupported property type '{$type->getName()}' for {$this->entityClass}::\${$name}.",
                ),
            },
            is_a($type->getName(), DateTimeImmutable::class, true) => ValueKind::DateTime,
            is_a($type->getName(), DateInterval::class, true) => ValueKind::Interval,
            is_subclass_of($type->getName(), BackedEnum::class) => ValueKind::Enum,
            is_a($type->getName(), Struct::class, true) => ValueKind::Struct,
            is_subclass_of($type->getName(), CustomValue::class) => ValueKind::Custom,
            is_a($type->getName(), DateTimeInterface::class, true) => throw new MetadataException(
                "Mutable date-time type '{$type->getName()}' is not supported for"
                . " {$this->entityClass}::\${$name}, use DateTimeImmutable.",
            ),
            isset($this->adapterMap()[$type->getName()]) => ValueKind::Custom,
            default => throw new MetadataException(
                "Unsupported property type '{$type->getName()}' for {$this->entityClass}::\${$name}.",
            ),
        };

        if (($isDate || $isTime) && $kind !== ValueKind::DateTime) {
            $attribute = $isDate ? 'Date' : 'Time';
            throw new MetadataException(
                "#[Type\\{$attribute}] is only allowed on DateTimeImmutable properties,"
                . " found on {$this->entityClass}::\${$name}.",
            );
        }

        return match (true) {
            $isDate => ValueKind::Date,
            $isTime => ValueKind::Time,
            default => $kind,
        };
    }

    private function resolveFieldName(ReflectionProperty $property): string
    {
        return $this->resolveScopedAttribute($property, Name::class)->name
            ?? $this->format->fieldName($property->getName());
    }

    /**
     * Resolves a repeatable format-scoped attribute: evaluated top-down in
     * declaration order, the first one matching the current format wins;
     * an attribute without scope is a catch-all and must be declared last.
     *
     * @template A of FormatScoped
     * @param class-string<A> $attributeClass
     * @return A|null
     */
    private function resolveScopedAttribute(ReflectionProperty $property, string $attributeClass): ?FormatScoped
    {
        $name = $property->getName();
        $label = '#[' . substr($attributeClass, (int) strrpos($attributeClass, '\\') + 1) . ']';
        $matched = null;
        $catchAllSeen = false;

        foreach ($property->getAttributes($attributeClass) as $attribute) {
            if ($catchAllSeen) {
                throw new MetadataException(
                    "Unreachable {$label} attribute on {$this->entityClass}::\${$name}:"
                    . " declared after a catch-all {$label} without format scope.",
                );
            }

            $instance = $attribute->newInstance();

            if ($instance->formats === []) {
                $catchAllSeen = true;
                $matched ??= $instance;
                continue;
            }

            foreach ($instance->formats as $scope) {
                if (!is_string($scope)
                    || (!class_exists($scope) && !interface_exists($scope))
                    || !is_a($scope, FormatScope::class, true)
                ) {
                    $described = is_string($scope) ? "'{$scope}'" : get_debug_type($scope);
                    throw new MetadataException(
                        "Invalid format scope {$described} in {$label} attribute"
                        . " on {$this->entityClass}::\${$name}, expected a class-string of "
                        . FormatScope::class . '.',
                    );
                }
                if ($matched === null && $this->format instanceof $scope) {
                    $matched = $instance;
                }
            }
        }

        return $matched;
    }
}
