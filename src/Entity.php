<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

/**
 * Marker interface of hydratable entities. It carries no methods — an entity
 * stays a plain data object with typed public properties — but it makes the
 * contract explicit: the hydrator refuses foreign objects early instead of
 * failing later with confusing field-mismatch errors, and IDEs can offer
 * only actual entities.
 */
interface Entity
{
}
