<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use JakubBoucek\Hydrator\Exception\ValueException;

/**
 * Maps foreign classes (types that exist independently of the hydrator,
 * e.g. ramsey/uuid) to intermediate native types — the external counterpart
 * of CustomValue. Adapters are registered on the factory as class-strings
 * (lazy: instantiated only when a processed entity actually uses a declared
 * class) or as instances (for adapters with dependencies); registration
 * order is binding, the first adapter declaring a class wins.
 *
 * Cacheability contract: provides() is a pure function of the class — no
 * side effects, no dependence on instance configuration — and its keys are
 * exact-match strings that may reference classes absent from the
 * application (optional dependencies); matching never triggers autoload.
 *
 * Null policy matches CustomValue: import() never receives null, export()
 * may return null (stored as NULL, collapses to plain null on the next
 * hydration). Adapters registered as class-strings must be constructible
 * without arguments; configuration belongs to a subclass or a registered
 * instance.
 */
interface TypeAdapter
{
    /**
     * Declares the supported classes and their intermediate native types.
     *
     * @return array<class-string, NativeType>
     */
    public static function provides(): array;

    /**
     * @param mixed $value The native type declared in provides() for
     *   $targetClass (already validated by the format codec).
     * @param class-string $targetClass
     * @throws ValueException
     */
    public function import(mixed $value, string $targetClass): object;

    /**
     * Returns the declared native type, or null for inner nullness.
     */
    public function export(object $value): int|float|string|bool|\DateTimeImmutable|\DateInterval|null;
}
