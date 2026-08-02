<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use Generator;
use IteratorAggregate;
use JakubBoucek\Hydrator\Exception\StreamException;

/**
 * Lazy single-pass stream of hydrated entities returned by
 * Hydrator::fromDataSet(). Nothing is buffered and the underlying data
 * source is not touched until the first consumption; once the stream is
 * consumed (iterated or collected), it cannot be consumed again — keeping
 * data around is the application's domain, not the library's.
 *
 * The stream keys pass through from the data source (a Nette Selection
 * keys its iteration by the primary key, a plain list yields sequential
 * keys), or come from the `keyBy` property requested on fromDataSet().
 *
 * The collect* terminals materialize the stream into an array — meant for
 * data sets that are small by design, where an array is the honest data
 * structure and lazy iteration would be ceremony.
 *
 * The class is deliberately not final (internals stay private), see the
 * final policy in CLAUDE.md.
 *
 * @template T of Entity
 * @implements IteratorAggregate<int|string, T>
 */
class EntitySet implements IteratorAggregate
{
    /** @var Generator<int|string, T>|null Null once consumed. */
    private ?Generator $generator;

    /**
     * @param Generator<int|string, T> $generator
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Returns the underlying generator; counts as the one allowed
     * consumption. The generator supports peeking: `current()` hydrates
     * the first entity without advancing, a subsequent foreach over the
     * same generator still starts from the first element.
     *
     * @return Generator<int|string, T>
     */
    public function getIterator(): Generator
    {
        return $this->consume();
    }

    /**
     * Materializes the stream into a plain list, discarding the keys.
     *
     * @return list<T>
     */
    public function collectList(): array
    {
        return iterator_to_array($this->consume(), preserve_keys: false);
    }

    /**
     * Materializes the stream into an array preserving the keys.
     * Duplicate keys overwrite (iterator_to_array semantics) — for a
     * positional collection use collectList().
     *
     * @return array<int|string, T>
     */
    public function collectMap(): array
    {
        return iterator_to_array($this->consume(), preserve_keys: true);
    }

    /**
     * @return Generator<int|string, T>
     */
    private function consume(): Generator
    {
        $generator = $this->generator;

        if ($generator === null) {
            throw new StreamException(
                'The entity set is already consumed: it is a lazy single-pass stream over the data'
                . ' source, so iterate or collect it exactly once and keep the result if you need'
                . ' the entities repeatedly.',
            );
        }

        // hand over the only reference — after full consumption nothing
        // keeps the source (e.g. a database result) alive through this set
        $this->generator = null;

        return $generator;
    }
}
