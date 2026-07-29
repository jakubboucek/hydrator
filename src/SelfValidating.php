<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use JakubBoucek\Hydrator\Exception\ValidationException;

/**
 * An entity with its own domain validation rules. Hydrator::validate() calls
 * the method after the completeness check passes. Implementing this interface
 * is the only coupling the entity gains — it stays a plain data object.
 */
interface SelfValidating
{
    /**
     * @throws ValidationException When the entity state violates its own rules.
     */
    public function validate(): void;
}
