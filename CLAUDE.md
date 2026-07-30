# jakubboucek/hydrator — project notes

Fast bidirectional hydrator between typed PHP entities and data records
(database rows, raw PDO rows, …). Extracted and generalized from the
hand-written `Hydrator` in the skradbuza.cz project and the POC port in
infosoud-checker (branch `poc-own-hydrator`).

## Development environment — Docker only

Never run PHP, Composer or MySQL locally on this machine — always through
Docker (`jakubboucek/lamp-devstack-php`, `-cli` variants, see
docker-compose.yml). One-shot commands via `docker compose run --rm` (no
long-running service needed; `exec` against a started service becomes
useful once a MySQL service is added):

```shell
docker compose run --rm php composer install
docker compose run --rm php composer test
docker compose run --rm php composer phpstan
docker compose run --rm php85 composer test   # PHP 8.5 variant
```

MariaDB integration tests (`tests/Integration.mariadb.*`) run only with
`DATABASE_DSN` set and skip otherwise. Locally:

```shell
docker compose up -d mysqldb
docker compose run --rm -e DATABASE_DSN='mysql:host=mysqldb;dbname=hydrator_test;charset=utf8mb4' php composer test
```

(User/password default to root/devstack; override with `DATABASE_USER`/
`DATABASE_PASSWORD`.) In CI they run as the dedicated "Integration tests"
job with a MariaDB 10.6 service container. Each test file owns its table —
Tester runs files in parallel. MariaDB 10.6 is the lowest devstack image;
the JSON column (LONGTEXT alias + json_valid check) is supported
everywhere since 10.2.7.

GitHub Actions run natively (no Docker needed there). CI marks lowest-deps
jobs `continue-on-error` — the run may look green while they fail, so
always check per-job conclusions (`gh run view <id> --json jobs`).

## Git workflow

- **Granular commits**: one cohesive topic per commit (feature, fix,
  convention, tooling) — never batch unrelated changes together, even at
  the cost of more commits. Stage selectively when needed.
- **Push sparingly**: not after every commit — push when the iteration is
  done or when CI verification is needed.

## Architecture and terminology

- **Entity** — a plain object with typed public properties implementing the
  empty `Entity` marker interface (decision 2026-07-29: the marker exists to
  reject foreign objects early and improve IDE hinting; no methods, no other
  coupling). Must be constructible without arguments.
- **Data** — the counterpart of the entity: an associative record
  (`array`/`Traversable` — Nette Row, ActiveRow, decoded JSON…). A key in
  Data is a **field**. Methods: `fromData()`, `fromDataSet()`, `toData()`.
- **Format** (`src/Format/`) — stateless definition of a representation:
  field naming convention + value codecs per logical type. Identified by
  class-string (the engine holds the instance internally); customization by
  subclassing. `NetteDatabase extends Mysql` (pass-through overrides),
  family marker interface `DatabaseFormat` for attribute scoping.
- **Struct** (`src/Struct.php` + `BaseStruct`, `DynamicStruct`,
  `TagListStruct`, `NoteListStruct`) — an autonomous structure in a single
  JSON column; the hydrator only passes serialized values, the format
  chooses the representation (DB pair `fromJson`/`toJson` = string, Json
  format = `fromArray`/`toArray` nested array). Signatures 1:1 with
  skradbuza `EntityStruct` for drop-in migration.
- **ValueKind** (`src/Metadata/`) — logical type resolved once from the PHP
  type + refining attributes (`#[Type\Date]`, `#[Type\Time]`): Int, Float, String, Bool,
  Enum, DateTime, Date, Time, Interval, Mixed.
- **PropertySlot** — precomputed per-property plan for one
  (entity class, format) pair; built once, cached in the `Hydrator`
  instance. `HydratorFactory` caches hydrators per (class, format).

## Load-bearing design decisions

- **Stream-first, no data caching.** The library never caches data or
  entities — that is the application's domain. `fromDataSet()` returns a
  lazy single-pass `Generator`. The only caches are entity metadata and
  compiled plans.
- **Attribute evaluation order**: format-scoped attributes (`#[Name]`,
  `#[Fraction]`, `#[DateFormat]` — shared `FormatScoped` interface and a
  generic resolver) are repeatable, evaluated top-down, first match wins
  (like `match`); scopes match via `instanceof` (concrete class, ancestor
  or family interface) — so a format subclass inherits scopes of its
  parents. An attribute declared after an unscoped catch-all →
  `MetadataException` (unreachable). Scopes are typed
  `class-string<FormatScope>` — a marker interface implemented by `Format`
  and extended by family interfaces (`DatabaseFormat`); custom family
  interfaces must extend `FormatScope` too.
- **Fraction and DateFormat** (2026-07-30): `#[Fraction(digits, omitZero)]`
  renders fractional seconds strictly on export (DATETIME(n)/TIME(n)
  analogy; digits 0–6, truncation not rounding, `digits: 0` = never) for
  DateTime/Time/Interval kinds; without it the old defaults stay.
  `#[DateFormat(pattern)]` is a custom pattern for DateTime/Date/Time
  kinds, bidirectional by construction: export `format()`, import
  `createFromFormat('!' . pattern)` strictly (no constructor fallback;
  '!' zeroes uncaptured parts → lossy patterns roundtrip
  deterministically). Deliberately NOT for Interval —
  `DateInterval::format()` has no parsing counterpart, a custom pattern
  would break the bidirectional promise (domain boundary: the library
  only offers transformations whose inverse it can guarantee; exotica →
  custom Format subclass). Mutually exclusive per (property, format) —
  different formats may use one each. Either attribute switches
  NetteDatabase export from instance pass-through to a finished string
  (Nette formats by PHP type and would drop the fraction — the
  motivation of the feature). Codec export signatures carry
  `?Fraction` — BC break for custom formats, allowed in 0.x.
- **Time zone is an application property** (factory-level, not per-format):
  every hydrated date-time is normalized into it (instances converted,
  naive strings interpreted, foreign offsets recalculated). Defaults to
  `date_default_timezone_get()`. Extraction formats strings in it;
  pass-through formats hand instances back unchanged.
- **Partial-update semantics**: extraction skips uninitialized properties
  (initialized null → field null). Completeness is never enforced by
  hydration/extraction.
- **No validation API** (removed 2026-07-29 after review): the earlier
  `isComplete()`/`validate()`/`SelfValidating` conflated three different
  material questions — insert-readiness (DB schema will accept the row),
  read-safety (no uninitialized-property access on read) and domain rules —
  each needing a different predicate, while partial-update makes
  "uninitialized" a legitimate state with meaning (field untouched in DB).
  Validation returns only when a concrete consumer use-case defines which
  question is actually being asked.
- **Hooks/visibility**: virtual get-only → skipped both ways; virtual with
  set hook → hydrated only; backed with hooks → both ways (hooks apply);
  `private(set)`/`protected(set)`/`readonly` → extracted, never hydrated
  (their fields are not required in input data).
- **Two mappings of a TIME column** (2026-07-29/30, verified empirically
  on MariaDB 12.2 + nette/database sources — MariaDB silently accepts
  −838:59:59…838:59:59 including '24:00:00'; TIME is documented as
  dual-purpose: time of day AND elapsed time):
  - **Interval kind (`DateInterval` property)** — the full MySQL TIME
    domain, never ISO durations. Every string format represents it as
    `HH:MM:SS` (sign, hours over 24, fractional seconds); the shared
    `importInterval()`/`exportInterval()` codec lives in the Format base,
    NetteDatabase overrides export to pass the instance through (Nette
    maps MySQL TIME to FIELD_TIME_INTERVAL → DateInterval on read and
    writes it signed via '%r%h:%I:%S'). Enforcing a day range here would
    be stricter than the DB and the Nette layer. `DateInterval` is used
    because PHP has no native date-less time type.
  - **Time kind (`#[Type\Time]` on DateTimeImmutable)** — the strictly
    day-scoped alternative (00:00:00 <= x < 24:00:00, enforced by the
    codec; modeled on Nette's pgsql FIELD_TIME). Wall time pinned to
    0001-01-01 by parsing `'0001-01-01 <time>'` directly — the date
    predates DST rules, so every wall time exists exactly once (and the
    direct parse avoids Nette's setDate-after-parse DST-gap flaw). Only
    the wall clock is meaningful, never the instant (year 1 = LMT); no
    zone conversion ever happens. Export is always the `H:i:s(.frac)`
    string, incl. NetteDatabase — Nette writes DateTimeInterface by PHP
    type as full `'Y-m-d H:i:s'` (no column-type lookup on write) and
    MariaDB would cast it into TIME only with a truncation Note.
    Nette-on-MySQL delivers TIME as DateInterval;
    `NetteDatabase::importTime()` converts it within the day scope and
    throws beyond it (such columns belong to a DateInterval property).
- **Structs** (2026-07-30): a NULL column always hydrates into an empty
  instance — struct properties are non-nullable by design so they are
  writable at any time (`$member->address->city = …` never fails; the
  struct kind is exempt from null-strictness). An empty struct is stored
  as NULL, never `'{}'`/`'[]'` — emptiness belongs to the struct
  (`toJson()` → null); in the Json format emptiness is explicit (`[]`).
  Struct classes need a no-arg constructor
  (`@phpstan-consistent-constructor` on BaseStruct). BaseStruct's lossy
  traits (unknown keys dropped, nulls filtered) are documented; the
  lossless variant is DynamicStruct. A property typed with a
  non-instantiable Struct (interface/abstract) → MetadataException.
- **Strictness**: missing field, null into non-nullable, wrong value type →
  `HydrationException` with `Class::$property` + field context. Value
  codecs in formats throw `ValueException`; the engine wraps it with
  context. Union/intersection types, mutable `DateTime`, unknown attribute
  scopes → `MetadataException` at metadata-build time.
- **Versioning 0.x** until the API stabilizes; breaking changes allowed in
  0.x. No `v1.0` until the design settles with real consumers.

## Roadmap (agreed 2026-07-29)

- **0.1** (tagged 2026-07-29) — engine, `NetteDatabase` + `Mysql` formats,
  `#[Name]` + `#[Type\Date]`, streaming data sets.
- **0.2** (implemented 2026-07-29) — `Json` format: IdentityConverter
  (camelCase as-is), date-time ↔ RFC 3339 with foreign-offset
  recalculation, date ↔ `Y-m-d`, strict native booleans, times as
  `HH:MM:SS`. Json deliberately does NOT implement DatabaseFormat, so
  database-scoped attributes skip it. Additions 2026-07-30:
  `#[Type\Time]`, `#[Fraction]`, `#[DateFormat]` (see decisions above).
- **0.3** (structs implemented 2026-07-30) — `Struct` interface +
  `BaseStruct`/`DynamicStruct`/`TagListStruct`/`NoteListStruct`,
  representation per format (DB string vs. Json nested array), MariaDB
  integration proof. Still open from the 0.3 scope: a general extension
  point for custom value types (the DatabaseValue idea).
- Later: composite keys, more `Type\*` attributes as needed.

First real consumer: project Lexion (infosoud-checker; PHP 8.5,
nette/database) — see its `docs/roadmap.md`, section "Typové entity a
de/hydratace". Performance target: parity with the POC harness
(`bin/poc-hydration.php` in branch `poc-hydration` there, ~490k rows/s).

## Legacy edge cases (documented by tests, 2026-07-30)

The library targets legacy projects too (no big ORM); edge cases are not
necessarily *solved* — speed and simplicity win — but they must be *known*
and failures loud. See `tests/Integration.mariadb.edgeCases.phpt`:

- **Zero dates** (`'0000-00-00'`): hydrate as null with an
  `E_USER_WARNING` (decision 2026-07-30; engine-level for DateTime/Date
  kinds on every format — parity with nette/database, which nulls them
  itself before we see them). Nullable property → null, non-nullable →
  the standard loud HydrationException. Without this, PHP would silently
  parse the raw string into a nonsense `-0001-11-30` date. Mapping the
  column as `?string` keeps the raw value exact.
- **Unsigned BIGINT beyond PHP_INT_MAX**: arrives as string on every
  path, the int cast silently saturates at PHP_INT_MAX — map such
  columns as `string`.
- **DECIMAL with >15 significant digits**: lossy in a `float` property —
  map as `string` where exactness matters (money).
- **PDO since PHP 8.1** returns native int/float by default; the legacy
  stringified mode (`ATTR_STRINGIFY_FETCHES`) is covered by tests too.

## Conventions

- Scaffold follows `jakubboucek/nette-http-request-strict-proxy`: Nette
  Tester (`.phpt` + `test()` function), PHPStan `level: max`
  (`treatPhpDocTypesAsCertain: false` — runtime guards on PHPDoc-certain
  types are intentional), no coding-standard tool, `code_analysis.yaml`
  workflow, `.gitattributes` export-ignore, MIT.
- **Final policy** (decision 2026-07-30): classes are open by default —
  the real encapsulation is `private` internals (a subclass can only
  wrap or replace the public API), and open classes keep the escape
  hatch for test fakes and behavior fixes in consumers (there are no
  service interfaces by design). `final` stays only where a subclass
  provably cannot work: **attributes** (the scoped-attribute resolver
  matches exact classes — a subclass would be silently ignored),
  **`Format::__construct`** (statelessness + class-string identity
  contract) and `@internal` metadata classes. Enums are final by nature.
- **Dependency constraints policy**: quality-check tools (phpstan/phpstan,
  nette/tester) are pinned to exact versions incl. patch — they execute
  the tests, they are not their subject, and a tool version drift must
  not produce failures unrelated to the library; bump deliberately in a
  dedicated iteration. Runtime-integration deps (nette/database) get a
  wide range instead — verifying compatibility across versions is the
  point. Dev floor `^3.2.8`: older 3.2.x work on PHP 8.4 but hit an
  E_DEPRECATED in Selection on PHP 8.5; `|| ^4.0` starts testing 4.0
  automatically once it goes stable.
- README in English, no badges, GitHub alert blockquotes.
