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
- **Database type nuances** — `DATE` vs `DATETIME` (`#[Type\Date]`) and `TIME` columns (day-scoped `#[Type\Time]` or full-range `DateInterval`) are first-class citizens.
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

Legacy zero dates (`'0000-00-00'`, `'0000-00-00 00:00:00'`) hydrate as `null` with an `E_USER_WARNING` — matching nette/database's behavior — so a non-nullable property over such data fails loudly instead of receiving a nonsense date.

## Formats

A *format* describes how values are represented in data: the field naming convention and the codecs for booleans, date-times, dates and intervals. Formats are stateless and identified by their class name:

- `Format\NetteDatabase` — for nette/database, which already converts values on both sides: instances pass through, booleans stay booleans. `fromDataSet()` auto-detects the primary key of a `Selection` (duck-typed, no hard dependency).
- `Format\Mysql` — for raw PDO/mysqli: date-times as `'Y-m-d H:i:s'`, dates as `'Y-m-d'`, booleans as `0`/`1`, TIME as `'HH:MM:SS'` strings.
- `Format\Json` — for decoded JSON payloads (APIs): property names as-is (camelCase), date-times as RFC 3339 (a foreign offset is recalculated into the app time zone), dates as `'Y-m-d'`, native booleans, times as `'HH:MM:SS'` strings.

### Export values by format

What `toData()` produces for each property type:

| Property type | NetteDatabase | Mysql | Json |
|---|---|---|---|
| `int`, `float`, `string` | as-is | as-is | as-is |
| `bool` | `bool` | `1` / `0` | `bool` |
| `BackedEnum` | backing value | backing value | backing value |
| `DateTimeImmutable` | instance <sup>1)</sup> | `'Y-m-d H:i:s'` <sup>2)</sup> | RFC 3339 <sup>2)</sup> |
| `#[Type\Date]` | instance <sup>1)</sup> | `'Y-m-d'` <sup>2)</sup> | `'Y-m-d'` <sup>2)</sup> |
| `#[Type\Time]` | `'H:i:s'` <sup>3)</sup> | `'H:i:s'` <sup>3)</sup> | `'H:i:s'` <sup>3)</sup> |
| `DateInterval` | instance <sup>1)</sup> | `'HH:MM:SS'` <sup>4)</sup> | `'HH:MM:SS'` <sup>4)</sup> |
| `mixed` / untyped | as-is | as-is | as-is |

<sup>1)</sup> Instance pass-through — the database layer formats it itself.\
<sup>2)</sup> Rendered in the application time zone.\
<sup>3)</sup> Wall clock of the value, no zone conversion; fractional seconds appended when non-zero. A plain time string is used even with nette/database — Nette would write an instance as a full `'Y-m-d H:i:s'`.\
<sup>4)</sup> Full TIME domain kept: sign, hours over 24, fractional seconds.

The `#[Fraction]` and `#[DateFormat]` attributes override these default renderings — see [Attributes](#attributes).

### Hydration inputs by format

What `fromData()` accepts for each property type:

| Property type | NetteDatabase | Mysql | Json |
|---|---|---|---|
| `int`, `float`, `string` | scalar (cast) | scalar (cast) | scalar (cast) |
| `bool` | `bool`, `0`/`1`, `'0'`/`'1'` | `bool`, `0`/`1`, `'0'`/`'1'` | `bool` only |
| `BackedEnum` | backing value <sup>5)</sup> | backing value <sup>5)</sup> | backing value <sup>5)</sup> |
| `DateTimeImmutable` | instance, string <sup>6)</sup> | instance, string <sup>6)</sup> | instance, string <sup>6)</sup> |
| `#[Type\Date]` | instance, string <sup>6)</sup> | instance, string <sup>6)</sup> | instance, string <sup>6)</sup> |
| `#[Type\Time]` | instance, `'HH:MM:SS'`, `DateInterval` <sup>7)</sup> | instance, `'HH:MM:SS'` <sup>7)</sup> | instance, `'HH:MM:SS'` <sup>7)</sup> |
| `DateInterval` | instance, `'HH:MM:SS'` <sup>8)</sup> | instance, `'HH:MM:SS'` <sup>8)</sup> | instance, `'HH:MM:SS'` <sup>8)</sup> |
| `mixed` / untyped | anything, as-is | anything, as-is | anything, as-is |

<sup>5)</sup> `int` or `string`, cast to the enum backing type, mapped via `::from()`.\
<sup>6)</sup> Any `DateTimeInterface` instance is converted into the application time zone; a naive string is interpreted in it, a string carrying its own offset is recalculated into it.\
<sup>7)</sup> Day range enforced (`00:00:00 <= x < 24:00:00`); a `DateInterval` beyond the day scope (Nette delivers those for MySQL TIME) is rejected.\
<sup>8)</sup> Full TIME domain: sign, hours over 24, fractional seconds.

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

`#[Type\Time]` refines a `DateTimeImmutable` property to a time-of-day value: the date is pinned to `0001-01-01` — a date that predates DST rules, so every wall time on it exists exactly once — and string formats represent it as `'HH:MM:SS'`.

A `TIME` column can therefore be mapped in two ways; pick by the domain of the column:

- **`DateInterval` property** — full MySQL TIME compatibility: sign and hours over 24 (`'-838:59:59'`…`'838:59:59'`), values a `DateTime` cannot hold. MySQL documents TIME as dual-purpose — time of day *and* elapsed time — and this mapping carries all of it.
- **`#[Type\Time]` on `DateTimeImmutable`** — strictly day-scoped (`00:00:00 <= x < 24:00:00`, enforced), with the comfort of the DateTime API.

> [!NOTE]
> With nette/database on MySQL, TIME columns arrive as `DateInterval` instances; the NetteDatabase format converts them for `#[Type\Time]` properties within the day range and rejects values beyond it — such columns belong to a `DateInterval` property.

`#[Fraction]` controls fractional seconds on export — the analogy of `DATETIME(n)`/`TIME(n)` column precision — for date-time, time and interval properties. Without it the format defaults apply (date-times render without a fraction, times and intervals append one when non-zero); with it the rendering is strict: exactly `digits` places (zero-padded, truncated), `digits: 0` never renders one, `omitZero: true` drops a zero-valued part:

```php
#[Fraction(6)]                          // DATETIME(6): always six places
public DateTimeImmutable $measuredAt;

#[Fraction(3, omitZero: true, formats: [Json::class])]  // milliseconds, only when non-zero
public DateTimeImmutable $processedAt;
```

`#[DateFormat]` sets a custom output pattern (a native PHP date format string) for date-time, date and time properties. The same pattern drives both directions — export via `format()`, import via `DateTimeImmutable::createFromFormat()`, strictly, with no fallback to constructor parsing — so a pattern capturing the full value roundtrips losslessly, and a lossy pattern (e.g. without seconds) zeroes the uncaptured parts deterministically instead of crashing:

```php
#[DateFormat('U')]                      // unix timestamp
public DateTimeImmutable $syncedAt;
```

`#[DateFormat]` is deliberately not available for `DateInterval`: `DateInterval::format()` has no parsing counterpart in PHP, so the bidirectional promise could not be kept — exotic interval renderings belong to a custom format subclass.

Both attributes are scoped like `#[Name]` and mutually exclusive per (property, format) — one property may combine a strict fraction for databases with a pattern for JSON:

```php
#[Fraction(3, formats: [DatabaseFormat::class])]
#[DateFormat('d.m.Y H:i', formats: [Json::class])]
public DateTimeImmutable $mixedUse;
```

> [!NOTE]
> With `#[Fraction]` or `#[DateFormat]` the NetteDatabase format exports a finished string instead of the instance — Nette's own `'Y-m-d H:i:s'` formatting would drop the fraction, which is the very motivation: `DATETIME(6)` columns keep their microseconds.

## Tests

```shell
composer install
composer run test
composer run phpstan
```

Integration tests against a real MariaDB server (common column types over PDO, mysqli and nette/database in every `convertBoolean`/`newDateTime` configuration) run when `DATABASE_DSN` (plus optional `DATABASE_USER`/`DATABASE_PASSWORD`) points to a server, and skip otherwise.

## License

MIT. See the [LICENSE](LICENSE) file.
