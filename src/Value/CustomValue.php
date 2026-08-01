<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Value;

/**
 * Family marker of custom value types made for the hydrator: a domain value
 * object mapped to a single column. Do not implement directly — implement
 * one of the typed sub-interfaces (StringValue, IntValue, FloatValue,
 * BoolValue), which declare the intermediate native type by their choice
 * and carry a precisely typed fromNative() (PHP parameter contravariance
 * prevents narrowing an inherited signature, hence one interface per type).
 *
 * Null policy (deliberately asymmetric): fromNative() never receives null —
 * a NULL field is decided by the property's nullability. toNative() may
 * return null (inner nullness only the value itself can see) — the field
 * is then stored as NULL, and on the next hydration the value collapses to
 * a plain null.
 *
 * For foreign classes that cannot implement this interface (ramsey/uuid, …)
 * see TypeAdapter.
 */
interface CustomValue
{
    public function toNative(): int|float|string|bool|\DateTimeImmutable|\DateInterval|null;
}
