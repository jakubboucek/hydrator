# Hydrator

Fast bidirectional hydrator between typed PHP entities and database rows (or other data formats), built for modern PHP.

Entities are plain data objects: typed public properties, [property hooks](https://www.php.net/manual/en/language.oop5.property-hooks.php), no magic getters/setters and no mandatory attributes. The only contract is the empty `Entity` marker interface — it keeps the entity a plain object while letting the hydrator (and your IDE) refuse foreign objects early instead of failing later with confusing field-mismatch errors. The hydrator maps entities to and from associative data — a database row, a raw PDO result or any other representation described by a *format*.

> [!WARNING]
> The library is in development stage (0.x versions): the API may change between minor versions until it stabilizes.

## Why another hydrator

General mapping libraries struggle with entities written in modern PHP style. This library is designed around them by design:

- **Property hooks aware** — a virtual get-only property is skipped in both directions, a property with a set hook is writable, `private(set)`/`protected(set)`/`readonly` properties are extracted but never written.
- **Partial updates** — extraction distinguishes *uninitialized* from *null*: only initialized properties produce fields, so a partially filled entity naturally becomes a partial `UPDATE`.
- **Pass-through of already-typed values** — layers like [nette/database](https://github.com/nette/database) return `DateTimeImmutable`, `bool` and `DateInterval` instances; the hydrator accepts them as-is instead of demanding strings.
- **Database type nuances** — `DATE` vs `DATETIME` (`#[Type\Date]`) and `TIME` columns (`DateInterval`) are first-class citizens.
- **Deterministic time zones** — every hydrated date-time is normalized into the application time zone.
- **Performance** — reflection runs once per entity class; per-row work is a plain loop over precomputed metadata (hundreds of thousands of rows per second). The library never caches data or entities, only its own metadata: data sets are processed as lazy single-pass streams.

## Installation

```shell
composer require jakubboucek/hydrator
```

Requires PHP 8.4+. No runtime dependencies.

## Usage

```php
use JakubBoucek\Hydrator\HydratorFactory;
use JakubBoucek\Hydrator\Format\NetteDatabase;

$factory = new HydratorFactory(
    format: NetteDatabase::class,                 // preferred format
    timeZone: new DateTimeZone('Europe/Prague'),  // app time zone (defaults to PHP default)
);

$articles = $factory->for(Article::class);

// single row: array or Traversable (Nette Row / ActiveRow)
$article = $articles->fromData($explorer->table('article')->get(1));

// whole result: lazy stream keyed by the table primary key
foreach ($articles->fromDataSet($explorer->table('article')) as $id => $article) {
    // ...
}

// partial update: only initialized properties are extracted
$patch = new Article();
$patch->title = 'Updated title';
$explorer->table('article')->where('id', $id)->update($articles->toData($patch));
```

The entity is a plain object:

```php
use JakubBoucek\Hydrator\Attribute\Type;
use JakubBoucek\Hydrator\Entity;

class Article implements Entity
{
    public int $id;
    public string $title;
    public ?string $note;
    public bool $published;
    public DateTimeImmutable $createdAt;

    #[Type\Date]                        // DATE column: no time part
    public DateTimeImmutable $publishedOn;

    public DateInterval $readingTime;   // TIME column
    public ArticleStatus $status;       // BackedEnum, mapped by backing value

    public string $label {              // virtual property: ignored by the hydrator
        get => "#{$this->id} {$this->title}";
    }
}
```

Property names map to field names by convention (camelCase ↔ snake_case by default, defined by the format).

### Strictness

Every writable property requires its field in data: a missing field, a `null` for a non-nullable property or a value of an unexpected type throws an exception with the entity class, property and field name in the message. Extra fields in data with no matching property are silently ignored, and fields of non-writable properties (`readonly`, `private(set)`, virtual get-only) are never required. All library exceptions implement the `JakubBoucek\Hydrator\Exception\HydratorException` marker interface.

## Formats

A *format* describes how values are represented in data: the field naming convention and the codecs for booleans, date-times, dates and intervals. Formats are stateless and identified by their class name:

- `Format\NetteDatabase` — for nette/database, which already converts values on both sides: instances pass through, booleans stay booleans. `fromDataSet()` auto-detects the primary key of a `Selection` (duck-typed, no hard dependency).
- `Format\Mysql` — for raw PDO/mysqli: date-times as `'Y-m-d H:i:s'`, dates as `'Y-m-d'`, booleans as `0`/`1`, TIME as `'HH:MM:SS'` strings.

Custom format = subclass:

```php
class UpperSnake extends Mysql
{
    protected function createNameConverter(): NameConverter
    {
        return new MyUpperSnakeConverter();
    }
}
```

Thanks to `instanceof` scope matching a subclass automatically inherits attribute scopes targeting its parents.

## Attributes

Attributes are opt-in escape hatches for edge cases — the default mapping is fully conventional.

`#[Name]` overrides the field name, optionally scoped to formats (a concrete class, ancestor, or a family interface like `Format\DatabaseFormat`). Attributes are evaluated top-down, first match wins — declare more specific scopes first; an unscoped attribute is a catch-all and must come last:

```php
use JakubBoucek\Hydrator\Attribute\Name;
use JakubBoucek\Hydrator\Format\DatabaseFormat;

class Legacy
{
    #[Name('some__name', [DatabaseFormat::class])]  // all database formats
    #[Name('someName')]                             // any other format
    public string $someName;
}
```

`#[Type\Date]` refines a `DateTimeImmutable` property to a date-only value (see above).

## Tests

```shell
composer install
composer run test
composer run phpstan
```

## License

MIT. See the [LICENSE](LICENSE) file.
