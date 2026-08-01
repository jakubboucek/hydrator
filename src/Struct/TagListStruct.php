<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator\Struct;

use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A list of unique string tags in a JSON column (`["a", "b"]`) — the
 * simplest showcase of a list-shaped Struct.
 *
 * @implements IteratorAggregate<int, string>
 */
class TagListStruct extends BaseStruct implements Countable, IteratorAggregate
{
    /** @var list<string> */
    public array $tags = [];

    public function add(string $tag): static
    {
        if (!$this->has($tag)) {
            $this->tags[] = $tag;
        }

        return $this;
    }

    public function remove(string $tag): static
    {
        $this->tags = array_values(array_filter($this->tags, static fn(string $t): bool => $t !== $tag));

        return $this;
    }

    public function has(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public static function fromArray(array $data): static
    {
        $self = new static();
        foreach ($data as $tag) {
            if (is_scalar($tag) || $tag instanceof \Stringable) {
                $self->add((string) $tag);
            }
        }

        return $self;
    }

    public function toArray(): array
    {
        return $this->tags;
    }

    public function toText(): string
    {
        return implode(', ', $this->tags);
    }

    public function getIterator(): Traversable
    {
        yield from $this->tags;
    }

    public function count(): int
    {
        return count($this->tags);
    }
}
