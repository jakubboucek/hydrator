<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use JakubBoucek\Hydrator\Exception\ValueException;

/**
 * An autonomous structure stored in a single JSON column. The hydrator never
 * looks inside — it only hands the serialized value over and asks for it
 * back; parsing, rendering, emptiness and the inner shape are fully the
 * struct's domain. Implementations must be constructible without arguments
 * and signal invalid input with a ValueException.
 *
 * Two representations, chosen by the format:
 *
 * - **database pair** (`fromJson`/`toJson`) — a JSON string. Null means an
 *   empty struct on both sides: an empty struct is stored as NULL (never as
 *   `{}` or `[]`), and a NULL column hydrates into an empty instance — a
 *   struct property always holds an instance, so it is writable at any time.
 * - **array pair** (`fromArray`/`toArray`) — a plain JSON-serializable
 *   key→value array or list, possibly nested, with no objects inside
 *   (dates as strings). Used by the Json format; emptiness is explicit
 *   there (`[]`).
 */
interface Struct
{
    /**
     * @throws ValueException
     */
    public static function fromJson(?string $json): static;

    public function toJson(): ?string;

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): static;

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array;
}
