<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

/**
 * Aggregate initialization state of an entity's stored values, relative
 * to the entity's own declaration: the backed public properties the
 * hydrator maps (virtual properties are outside the domain and never
 * count). Deliberately format- and attribute-independent — the state
 * says nothing about storage readiness: Complete does not mean "ready
 * to INSERT" nor "safe to read", Empty does not mean "invalid".
 */
enum InitializationState
{
    /**
     * No mapped property is initialized. Also reported by an entity
     * with no mapped properties at all.
     */
    case Empty;

    /** Some mapped properties are initialized, some are not. */
    case Partial;

    /** Every mapped property is initialized. */
    case Complete;
}
