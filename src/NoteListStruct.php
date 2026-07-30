<?php

declare(strict_types=1);

namespace JakubBoucek\Hydrator;

use Countable;
use DateTimeImmutable;
use IteratorAggregate;
use Traversable;

/**
 * A list of notes attached to a record — one of the most common legacy
 * JSON-column patterns. Each note is a plain item `{text, author?, date?}`;
 * objects never enter the structure (dates are formatted to strings at the
 * boundary, see add()).
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
class NoteListStruct extends BaseStruct implements Countable, IteratorAggregate
{
    public const string FieldText = 'text';
    public const string FieldAuthor = 'author';
    public const string FieldDate = 'date';

    /** @var list<array<string, mixed>> */
    public array $notes = [];

    public function add(
        string $text,
        ?string $author = null,
        DateTimeImmutable|string|null $date = null,
    ): static {
        $note = array_filter(
            [
                self::FieldText => $text,
                self::FieldAuthor => $author,
                self::FieldDate => $date instanceof DateTimeImmutable ? $date->format('Y-m-d H:i:s') : $date,
            ],
            static fn(?string $value): bool => $value !== null && $value !== '',
        );

        $this->notes[] = $note;

        return $this;
    }

    public function remove(int $index): static
    {
        $notes = $this->notes;
        unset($notes[$index]);
        $this->notes = array_values($notes);

        return $this;
    }

    public static function fromArray(array $data): static
    {
        $self = new static();
        /** @var list<array<string, mixed>> $notes */
        $notes = array_values(array_filter($data, is_array(...)));
        $self->notes = $notes;

        return $self;
    }

    public function toArray(): array
    {
        return $this->notes;
    }

    /**
     * Plain-text rendering, one note per line: `date (author): text`.
     */
    public function toText(): string
    {
        $lines = [];
        foreach ($this->notes as $note) {
            $date = isset($note[self::FieldDate]) && is_scalar($note[self::FieldDate])
                ? (string) $note[self::FieldDate]
                : '';
            $author = isset($note[self::FieldAuthor]) && is_scalar($note[self::FieldAuthor])
                ? '(' . $note[self::FieldAuthor] . ')'
                : '';
            $text = isset($note[self::FieldText]) && is_scalar($note[self::FieldText])
                ? (string) $note[self::FieldText]
                : '';

            $prefix = trim($date . ' ' . $author);
            $lines[] = ($prefix === '' ? '' : $prefix . ': ') . $text;
        }

        return implode("\n", $lines);
    }

    public function getIterator(): Traversable
    {
        yield from $this->notes;
    }

    public function count(): int
    {
        return count($this->notes);
    }
}
