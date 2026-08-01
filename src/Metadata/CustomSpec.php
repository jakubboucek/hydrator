<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Metadata;

use JakubBoucek\Hydrator\TypeAdapter;

/**
 * Resolved conversion plan of a custom-typed property: the intermediate
 * native kind and (for foreign classes) the adapter class handling it;
 * a null adapter means the value class implements CustomValue itself.
 *
 * @internal
 */
final readonly class CustomSpec
{
    /**
     * @param class-string<TypeAdapter>|null $adapterClass
     */
    public function __construct(
        public ValueKind $nativeKind,
        public ?string $adapterClass,
    ) {
    }
}
