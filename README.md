# Hydrator

Fast bidirectional hydrator between typed PHP entities and database rows (or other data formats), built for modern PHP.

Entities are plain data objects: typed public properties, [property hooks](https://www.php.net/manual/en/language.oop5.property-hooks.php), no magic getters/setters and no mandatory attributes. The only contract is the empty `Entity` marker interface — it keeps the entity a plain object while letting the hydrator (and your IDE) refuse foreign objects early instead of failing later with confusing field-mismatch errors. The hydrator maps entities to and from associative data — a database row, a raw PDO result or any other representation described by a *format*.

> [!WARNING]
> The library is in development stage (0.x versions): the API may change between minor versions until it stabilizes.

## Why another hydrator

General mapping libraries struggle with entities written in modern PHP style. This library is designed around them by design:

- **Property hooks aware** — the hydrator works with the stored state of backed public properties as an ordinary external caller: hooks on backed properties apply as for any other code, `private(set)`/`protected(set)`/`readonly` properties are extracted but never written, and virtual properties (no backing store) are ignored entirely — get/set hooks stay a private interface between the entity and the application.
- **Partial updates** — extraction distinguishes *uninitialized* from *null*: only initialized properties produce fields, so a partially filled entity naturally becomes a partial `UPDATE`; `isInitialized()` and `getInitializedPropertyNames()` let application code ask what a partial entity carries.
- **Pass-through of already-typed values** — layers like [nette/database](https://github.com/nette/database) return `DateTimeImmutable`, `bool` and `DateInterval` instances; the hydrator accepts them as-is instead of demanding strings.
- **Database type nuances** — `DATE` vs `DATETIME` (`#[Type\Date]`) and `TIME` columns (day-scoped `#[Type\Time]` or full-range `DateInterval`) are first-class citizens.
- **Deterministic time zones** — every hydrated date-time is normalized into the application time zone.
- **Performance** — reflection runs once per entity class; per-row work is a plain loop over precomputed metadata (hundreds of thousands of rows per second). The library never caches data or entities, only its own metadata: data sets are processed as lazy single-pass streams.

### Built for gradual modernization

The hydrator is designed to work as an intermediate step when modernizing a legacy application: it requires no change of the database-access paradigm and no changes to the database structure. Retrofitting Doctrine or another full ORM into a large existing codebase tends to be a demanding, all-or-nothing endeavor; typed entities backed by this library can instead be adopted piecemeal — one table or one query at a time — while the rest of the application keeps its existing database layer, making it a practical vehicle for refactoring database access gradually.

The only hard requirement is PHP 8.4. For an older application that is usually a far smaller obstacle than a data-access rewrite: a PHP upgrade is well supported by automated tooling (such as Rector), whereas replacing the database layer of a grown codebase rarely is.

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

// whole result: lazy single-pass stream; a Selection keys it by its primary key
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

### Data sets and streaming

`fromDataSet()` returns an `EntitySet` — a lazy, single-pass stream of hydrated entities. Nothing is buffered and the data source is not touched until the first consumption; rows hydrate one by one as you iterate, so a result of any size streams in constant memory.

Stream keys are transparent: the keys of the source iteration pass through unchanged (a nette/database `Selection` keys its rows by the table primary key, a plain list yields sequential keys). To re-key the stream, pass `keyBy:` with an entity **property** name — the key is read from the hydrated entity, so it carries the property's type (a stringifying driver still yields `int` keys) and the format's naming convention never leaks into calling code. The property must be a hydrated, non-nullable `int` or `string` property — anything else fails loudly before the source is touched:

```php
// stream keyed by the source (Selection = primary key)
foreach ($articles->fromDataSet($explorer->table('article')) as $id => $article) { /* … */ }

// re-keyed by a property, materialized into a lookup map
$byId = $articles->fromDataSet($pdo->query('SELECT * FROM article'), keyBy: 'id')->collectMap();

// materialized into a plain list, keys dropped
$today = $items->fromDataSet($rows)->collectList();
```

The set is strictly single-pass: once consumed — iterated or collected, even partially — a second consumption attempt throws a `StreamException`. The library never keeps data or entities around; holding onto results is the application's decision. For data sets that are small by design that decision is first-class: the `collect*` terminals materialize the stream into an array — `collectList()` discards keys, `collectMap()` preserves them (duplicate keys overwrite, as with `iterator_to_array()`). The vocabulary is intentional: lazy streaming is the default state, materialization is a conscious, named, greppable termination of the stream.

There is deliberately no eager variant on the hydrator or factory and no re-iterable set — the lazy path stays the easiest one, and an API that buffered or re-queried behind the scenes would only move the memory decision out of sight.

### Strictness

Every writable property requires its field in data: a missing field, a `null` for a non-nullable property or a value of an unexpected type throws an exception with the entity class, property and field name in the message. Extra fields in data with no matching property are silently ignored, and fields of non-writable backed properties (`readonly`, `private(set)`) are never required. Virtual properties have no stored state, so the hydrator ignores them entirely — a data key matching a virtual property's would-be field is foreign data like any other extra key. All library exceptions implement the `JakubBoucek\Hydrator\Exception\HydratorException` marker interface.

Legacy zero dates (`'0000-00-00'`, `'0000-00-00 00:00:00'`) hydrate as `null` with an `E_USER_WARNING` — matching nette/database's behavior — so a non-nullable property over such data fails loudly instead of receiving a nonsense date.

Both strictness rules have explicit, per-call switches on `fromData()`/`fromDataSet()`:

- **`allowPartial: true`** tolerates missing fields — the corresponding properties stay *uninitialized*, mirroring the partial extraction: a sparse `SELECT id, title` hydrates a sparse entity whose `toData()` produces exactly those fields back. Combined with `into:` it acts as a merge/patch — absent fields keep the target's current values. Beware that the strict default is what catches column-name typos; pair `allowPartial` with `rejectUnknown` to keep that protection.
- **`rejectUnknown: true`** tightens the opposite direction: a data key that maps to no entity property throws (known keys cover all backed mapped properties including the non-writable ones, so a full-row roundtrip passes; a key matching a virtual property's field is rejected like any unknown key, with a message pointing out the match).

There is deliberately no factory-wide default for either switch — tolerance is a per-call decision, not a mode.

### Asking what a partial entity carries

A partially hydrated entity *is* the patch — but application code cannot just read its properties, because reading an uninitialized typed property is a fatal `Error`. For most cases PHP itself has the answer: `isset()` and the `??`/`??=` operators are safe on uninitialized typed properties, and for a **non-nullable** property they answer exactly:

```php
$favorite->position ??= $this->nextPosition($favorite->userId);  // fallback only when not set

if (isset($entity->id)) { /* update */ } else { /* insert */ }   // upsert dispatch
```

Where the native idioms fall short, the hydrator answers — from the backing store, in property vocabulary:

```php
$hydrator->isInitialized($entity, 'note');       // true also for a stored null
$hydrator->getInitializedPropertyNames($entity); // e.g. ['id', 'note'] — names only, never values
```

- **Nullable properties with patch semantics** — `isset()` conflates a stored `null` ("set the column to NULL") with uninitialized ("don't touch it"); `isInitialized()` keeps them apart.
- **Entities with get hooks** — `isset()` invokes the hook, which crashes on an uninitialized backing; the hydrator reads the backing store via reflection and never invokes hooks, so no user code runs.
- **Generic code** — a generic save/patch routine can branch on `getInitializedPropertyNames()` without touching a single value.

An unknown property name throws a `MetadataException` — a typo is a bug, not "not set". So does asking about a virtual property: it has no stored state to ask about. The promise is precisely *the stored value is initialized*, not *reading is safe* — a get hook may still fail on its own uninitialized dependencies. Write get hooks to tolerate uninitialized state (or accept the consequences): the hydrator never forces partial entities on you — the strict default requires every field — it only enables them.

> [!IMPORTANT]
> `toData()` is not the way to ask this question. Its output is addressed to the storage driver — field names and format-encoded values. The point of the hydrator is that application code never speaks that vocabulary.

## Formats

A *format* describes how values are represented in data: the field naming convention and the codecs for booleans, date-times, dates and intervals. Formats are stateless and identified by their class name:

- `Format\NetteDatabase` — for nette/database, which already converts values on both sides: instances pass through, booleans stay booleans.
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
| `Struct` | JSON string <sup>5)</sup> | JSON string <sup>5)</sup> | nested array |
| custom types | by native type | by native type | by native type |
| `mixed` / untyped | as-is | as-is | as-is |

<sup>1)</sup> Instance pass-through — the database layer formats it itself.\
<sup>2)</sup> Rendered in the application time zone.\
<sup>3)</sup> Wall clock of the value, no zone conversion; fractional seconds appended when non-zero. A plain time string is used even with nette/database — Nette would write an instance as a full `'Y-m-d H:i:s'`.\
<sup>4)</sup> Full TIME domain kept: sign, hours over 24, fractional seconds.\
<sup>5)</sup> The struct's own `toJson()` rendering; an empty struct is stored as `NULL`.

The `#[Fraction]` and `#[DateFormat]` attributes override these default renderings — see [Attributes](#attributes).

### Hydration inputs by format

What `fromData()` accepts for each property type:

| Property type | NetteDatabase | Mysql | Json |
|---|---|---|---|
| `int`, `float`, `string` | scalar (cast) | scalar (cast) | scalar (cast) |
| `bool` | `bool`, `0`/`1`, `'0'`/`'1'` | `bool`, `0`/`1`, `'0'`/`'1'` | `bool` only |
| `BackedEnum` | backing value <sup>6)</sup> | backing value <sup>6)</sup> | backing value <sup>6)</sup> |
| `DateTimeImmutable` | instance, string <sup>7)</sup> | instance, string <sup>7)</sup> | instance, string <sup>7)</sup> |
| `#[Type\Date]` | instance, string <sup>7)</sup> | instance, string <sup>7)</sup> | instance, string <sup>7)</sup> |
| `#[Type\Time]` | instance, `'HH:MM:SS'`, `DateInterval` <sup>8)</sup> | instance, `'HH:MM:SS'` <sup>8)</sup> | instance, `'HH:MM:SS'` <sup>8)</sup> |
| `DateInterval` | instance, `'HH:MM:SS'` <sup>9)</sup> | instance, `'HH:MM:SS'` <sup>9)</sup> | instance, `'HH:MM:SS'` <sup>9)</sup> |
| `Struct` | JSON string, `NULL` <sup>10)</sup> | JSON string, `NULL` <sup>10)</sup> | array, `null` <sup>10)</sup> |
| custom types | by native type | by native type | by native type |
| `mixed` / untyped | anything, as-is | anything, as-is | anything, as-is |

<sup>6)</sup> `int` or `string`, cast to the enum backing type, mapped via `::from()`.\
<sup>7)</sup> Any `DateTimeInterface` instance is converted into the application time zone; a naive string is interpreted in it, a string carrying its own offset is recalculated into it.\
<sup>8)</sup> Day range enforced (`00:00:00 <= x < 24:00:00`); a `DateInterval` beyond the day scope (Nette delivers those for MySQL TIME) is rejected.\
<sup>9)</sup> Full TIME domain: sign, hours over 24, fractional seconds.\
<sup>10)</sup> Parsed by the struct itself (`fromJson`/`fromArray`); `NULL` hydrates into an empty struct instance — see [Structs](#structs).

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

## Structs

A *struct* is an autonomous structure stored in a single JSON column: for the database the column stays an ordinary string, but the entity works with it as a typed object. The hydrator never looks inside — it hands the serialized value to the struct and asks for it back; parsing, rendering and emptiness are fully the struct's domain. The `Struct` interface carries two representations: `fromJson`/`toJson` (databases) and `fromArray`/`toArray` (plain data, used by the Json format).

```php
use JakubBoucek\Hydrator\Struct\BaseStruct;
use JakubBoucek\Hydrator\Entity;
use JakubBoucek\Hydrator\Struct\NoteListStruct;

class AddressStruct extends BaseStruct
{
    public ?string $city = null;
    public ?string $street = null;
    public ?string $zip = null;
}

class Member implements Entity
{
    public int $id;
    public AddressStruct $address;   // non-nullable: an instance always exists
    public NoteListStruct $notes;
}

$member = $members->fromData($row);
$member->address->city = 'Plzeň';    // writable at any time, no null-checks
$member->notes->add('Paid by wire', 'admin', new DateTimeImmutable());
```

Rules of the mechanism:

- **An instance always exists**: a `NULL` column hydrates into an empty struct, so struct properties are declared non-nullable and are writable at any time. Partial-update semantics stay untouched (an uninitialized property still produces no field).
- **An empty struct is stored as `NULL`**, never as `'{}'` or `'[]'` — emptiness is defined by the struct itself (`toJson()` returning null).
- In the Json format structs travel as **nested arrays** and emptiness is explicit (`[]`).
- Structs must be constructible without arguments, and the array representation must stay plain JSON-serializable data — no objects inside (dates as strings).

Bundled implementations: `BaseStruct` (declared fields; unknown keys are dropped and nulls filtered — documented lossy traits), `DynamicStruct` (lossless free-form, an stdClass analogy), and the list-shaped showcases `TagListStruct` and `NoteListStruct` (`add…`/`remove…`, iteration, `toText()`).

## Custom types

A custom type maps a domain value object to a single column through an *intermediate native type*: the value first passes the format codec of that native type — with all its strictness — and only then reaches the custom conversion. Custom types are therefore format-blind: the same type renders as `'Y-m-d H:i:s'` in Mysql, RFC 3339 in Json and an instance pass-through with nette/database, and how a bool or a date-time is represented never becomes the custom code's business.

**Own types** implement one typed sub-interface of the `CustomValue` marker — the interface choice declares the native type: `StringValue`, `IntValue`, `FloatValue`, `BoolValue`, `DateTimeValue`, `IntervalValue`.

```php
use JakubBoucek\Hydrator\Value\IntValue;

final class Money implements IntValue
{
    private function __construct(
        public readonly int $cents,
    ) {}

    public static function fromNative(int $value): static   // exact type, no unions
    {
        return new static($value);
    }

    public function toNative(): ?int
    {
        return $this->cents;
    }
}
```

**Foreign classes** — types that exist independently and cannot implement the interface (ramsey/uuid and friends) — get a registered `TypeAdapter`:

```php
use JakubBoucek\Hydrator\Value\NativeType;
use JakubBoucek\Hydrator\Adapter\TypeAdapter;

final class UuidAdapter implements TypeAdapter
{
    public static function provides(): array
    {
        return [
            UuidInterface::class => NativeType::String,
            LazyUuidFromString::class => NativeType::String,
        ];
    }

    public function import(mixed $value, string $targetClass): object
    {
        return Uuid::fromString((string) $value);
    }

    public function export(object $value): string
    {
        return (string) $value;
    }
}

$factory = new HydratorFactory(
    format: NetteDatabase::class,
    adapters: [UuidAdapter::class, new GeoAdapter($resolver)],
);
```

Rules of the mechanism:

- **Registration order is binding** — the first adapter declaring a class wins. Class-strings load lazily: an adapter is instantiated only when a processed entity actually uses a declared class. Instances suit adapters with dependencies and win over a class-string registration of the same class.
- **`provides()` is a pure function of the class**: exact-match string keys that may reference classes absent from the application (optional dependencies) — matching never triggers autoload and the capability map is plain, cacheable data by contract.
- **Adapters cannot shadow natively handled classes** (`DateTimeImmutable`, `DateInterval`, `BackedEnum`, `Struct` and `CustomValue` implementations) — that fails loudly at metadata build.
- **Null policy is deliberately asymmetric**: `fromNative()`/`import()` never receive null — a `NULL` field is decided by the property's nullability — while `toNative()`/`export()` may return null for inner nullness; the field is then stored as `NULL` and the value collapses to a plain null on the next hydration.

## Tests

```shell
composer install
composer run test
composer run phpstan
```

Integration tests against a real MariaDB server (common column types over PDO, mysqli and nette/database in every `convertBoolean`/`newDateTime` configuration) run when `DATABASE_DSN` (plus optional `DATABASE_USER`/`DATABASE_PASSWORD`) points to a server, and skip otherwise.

## License

MIT. See the [LICENSE](LICENSE) file.
