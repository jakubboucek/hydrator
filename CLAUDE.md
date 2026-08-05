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
job with a MariaDB 10.6 service container. Each test file owns a whole
database (`hydrator_test_<name>` via `Mariadb::freshDatabase()`) — Tester
runs files in parallel and Nette Structure enumerates every table of a
database on load, so a shared database races concurrent DROP/CREATE
(that was the cause of the early transient failures). Prefer
`docker compose up -d --wait mysqldb` — the service has a healthcheck; a
freshly started server otherwise flakes. MariaDB 10.6 is the lowest
devstack image; the JSON column (LONGTEXT alias + json_valid check) is
supported everywhere since 10.2.7.

GitHub Actions run natively (no Docker needed there). CI marks lowest-deps
jobs `continue-on-error` — the run may look green while they fail, so
always check per-job conclusions (`gh run view <id> --json jobs`).

## Git workflow

- **Granular commits**: one cohesive topic per commit (feature, fix,
  convention, tooling) — never batch unrelated changes together, even at
  the cost of more commits. Stage selectively when needed.
- **Push sparingly**: not after every commit — push when the iteration is
  done or when CI verification is needed.
- **Releases**: annotated tags with a real description, created only on
  the user's explicit instruction (as is merging branches); Packagist is
  handled manually by the user. GitHub Releases mirror the tags: the
  release title is the bare version (`0.6.0`, no `v` prefix), the notes
  are the tag message verbatim (its subject line as the first line of
  the description, PGP signature stripped). Design changes are proposed and approved
  before implementation — the user drives decisions, iterations are
  design-dialogue first, code second.

## Architecture and terminology

Namespace layout (cleanup 2026-08-01): the root keeps the facade
(`Hydrator`, `HydratorFactory`), the stream wrapper (`EntitySet`) and the
two most-imported contracts (`Entity`, `Struct` — PHP allows the `Struct`
class and the `Struct\` namespace to coexist); families live in
`Converter\`, `Struct\`, `Value\` and `Adapter\`, next to the existing
`Attribute\`, `Format\`, `Exception\` and `Metadata\`.

- **Entity** — a plain object with typed public properties implementing the
  empty `Entity` marker interface (decision 2026-07-29: the marker exists to
  reject foreign objects early and improve IDE hinting; no methods, no other
  coupling). Must be constructible without arguments.
- **Data** — the counterpart of the entity: an associative record
  (`array`/`Traversable` — Nette Row, ActiveRow, decoded JSON…). A key in
  Data is a **field**. Methods: `fromData()`, `fromDataSet()`, `toData()`.
- **EntitySet** (`src/EntitySet.php`) — the lazy single-pass stream
  returned by `fromDataSet()`: wraps one generator, consumption is
  one-shot (a second attempt throws `Exception\StreamException`), the
  materializing terminals `collectList()`/`collectMap()` are thin
  `iterator_to_array` sugar for data sets small by design.
- **Format** (`src/Format/`) — stateless definition of a representation:
  field naming convention + value codecs per logical type. Identified by
  class-string (the engine holds the instance internally); customization by
  subclassing. `NetteDatabase extends Mysql` (pass-through overrides),
  family marker interface `DatabaseFormat` for attribute scoping.
- **Custom types** (`CustomValue` marker + typed sub-interfaces
  StringValue/IntValue/FloatValue/BoolValue/DateTimeValue/IntervalValue,
  `TypeAdapter` + `NativeType`) — a domain value object over a single
  column, converted through an intermediate native type that passes the
  format codecs first (custom code is format-blind, formats untouched).
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
  lazy single-pass `EntitySet` (0.5, design dialogue 2026-08-02/03,
  triggered by the first firovo.cz deployment): the source is untouched
  until the first consumption, a second consumption — iterate or
  collect, any combination, including after a `break` — throws
  `StreamException` instead of PHP's cryptic generator-rewind error. The
  raw generator from `getIterator()` deliberately supports the peek
  pattern (`current()` hydrates the first entity without advancing, a
  subsequent foreach still delivers the whole stream — e.g. analyze the
  first row before writing a CSV). `collectList()` drops keys,
  `collectMap()` preserves them (duplicates overwrite — plain
  `iterator_to_array` semantics, a by-design iterator property, not the
  hydrator's problem; documented modestly only). The collect* vocabulary
  is deliberate: materialization is a conscious, greppable stream
  termination for data sets small by design; asymmetry of convenience —
  the lazy path stays the easiest. The only caches are entity metadata
  and compiled plans.
- **Stream keys are transparent** (0.5): the source's own iteration keys
  pass through (Nette `Selection` keys its iteration by the PK natively
  — which exposed the former duck-typed `getPrimary()` autodetection as
  a reimplementation of information the source already carried;
  `Format::detectKeyField()` removed). Explicit `keyBy` is an entity
  **property** name — the API never speaks field names (the caller would
  need to know the format's NameConverter). The key is read from the
  **hydrated entity** (simplification 2026-08-03): keys carry the
  property's type, uniform across drivers (stringified PDO still keys by
  int). An unusable keyBy property fails eagerly with MetadataException
  at the `fromDataSet()` call, before the source is touched: unknown,
  not int/string-kinded (custom/enum objects cannot key an array —
  exotic keys are manual-foreach domain), non-writable (never hydrated)
  or nullable. Runtime: `allowPartial` leaving the key property
  uninitialized → `HydrationException` — via a zero-cost guard (plain
  property read in try/catch, PHP try is free until an exception flies;
  reflection `isInitialized()` runs only on the error path, and only to
  avoid relabeling an Error thrown by a get hook). Per-row reflection in
  the hot loop is forbidden territory (2026-08-03; extraction's
  per-property `isInitialized()` is the accepted, documented exception).
  Rejected on the way (do not
  re-propose): eager factory method (`fromDataList()` — would make
  eager the default mindset), re-iterable set, dev-mode size warnings,
  a `first()` terminal (would shadow the legit peek pattern and motivate
  `fromDataSet()` where `fromData($query->fetch())` belongs), field-name
  `keyBy`, reading the key from the raw row (pre-hydration values leak
  driver quirks into keys and need extra plumbing), moving `keyBy` onto
  `collectMap()` (keying belongs to the stream; collect* stays pure
  sugar), format-driven key autodetection.
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
  hydration/extraction. Input switches (2026-08-01, per-call only — no
  factory-wide default, that would be the tolerant-sponge anti-pattern):
  `allowPartial` tolerates missing fields (properties stay uninitialized;
  the input mirror of partial extraction; with `into:` = merge/patch,
  absent fields keep current values; struct always-instance rule applies
  only to present fields), `rejectUnknown` throws on data keys mapping to
  no property (known = fields of all mapped properties incl. non-writable
  — extraction produces them; implemented as one array_diff_key against a
  precomputed field set, zero cost when off). allowPartial alone loses
  typo detection — pairing with rejectUnknown restores it.
- **No validation API** (removed 2026-07-29 after review): the earlier
  `isComplete()`/`validate()`/`SelfValidating` conflated three different
  material questions — insert-readiness (DB schema will accept the row),
  read-safety (no uninitialized-property access on read) and domain rules —
  each needing a different predicate, while partial-update makes
  "uninitialized" a legitimate state with meaning (field untouched in DB).
  Validation returns only when a concrete consumer use-case defines which
  question is actually being asked.
- **Hooks/visibility** (narrowed 2026-08-05, 0.6): the mapped domain is
  the stored state of **backed public properties**; the hydrator acts
  as an ordinary external caller, so participation follows PHP
  visibility natively — backed hooks apply as for any caller,
  `private(set)`/`protected(set)`/`readonly` → extracted, never
  hydrated (their fields are not required in input data). Virtual
  properties (no backing store) → ignored entirely, both directions;
  get/set hooks are a private interface between the entity and the
  application. The pre-0.6 virtual-with-set-hook hydration was removed
  as the only place the engine actively opted into hook machinery (see
  the 0.6 roadmap entry).
- **Field vocabulary is internal** (2026-08-05): the application speaks
  property names exclusively; field names and format-encoded value
  representations are the vocabulary of the hydrator↔storage boundary.
  `toData()` output is an opaque payload addressed to the storage
  driver — application logic must never introspect or hand-assemble it
  (both keys and values are format-encoded). Every API speaks property
  names (precedent: `keyBy`). Consequence: property-vocabulary state
  introspection can only be provided by the hydrator
  (`isInitialized()`/`getInitializedPropertyNames()`).
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
- **Custom types** (2026-08-01): one concept, two attachment points —
  typed sub-interfaces of `CustomValue` for own classes (the interface
  choice declares the intermediate native type; per-type interfaces
  because PHP parameter contravariance forbids narrowing an inherited
  fromNative(); the marker carries the covariantly narrowable
  toNative()), `TypeAdapter` for foreign classes (ramsey/uuid style).
  Values pass the format codec of the intermediate type first. Adapter
  registration: class-strings (lazy) or instances (dependencies;
  configured instance wins, two different instances of one class =
  error), order binding with first-win; `provides()` is a static pure
  function with exact-match keys that may reference absent classes — no
  autoload on matching, capability map cacheable by contract (future
  `adapterLoader` DI hook is an additive extension). Adapters cannot
  shadow natively handled classes (loud MetadataException). Null policy
  deliberately asymmetric: fromNative()/import() never receive null,
  toNative()/export() may return it (collapses to plain null on the next
  hydration). Rejected on the way (do not re-propose): a single
  BackedEnum-style interface with a `$value { get; }` property — mixes
  the de/hydration contract into the object's own API and excludes
  foreign classes; a single fromNative(union) interface — PHP parameter
  contravariance forbids implementations narrowing it.
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
  integration proof.
- **0.4** (implemented 2026-08-01) — custom value types (see the decision
  above): CustomValue sub-interfaces, TypeAdapter registry, intermediate
  native types incl. DateTime/Interval. Completes the original roadmap
  (the DatabaseValue idea). Namespace cleanup in the same release.
- **0.4.1** (2026-08-01) — per-call input switches `allowPartial` +
  `rejectUnknown` (see the partial-update decision).
- **0.5** (implemented 2026-08-03, branch `entity-set`) — `EntitySet`
  wrapper for `fromDataSet()` (BC break of the return type),
  `StreamException`, transparent key passthrough, property-based
  `keyBy`, removal of `Format::detectKeyField()` (see the stream-first
  and stream-keys decisions).
- **0.6** (implemented 2026-08-05, branch `initialized-properties`,
  design dialogue on issue #1) — partial-entity introspection + domain
  narrowing (plus the `Format::fieldName()` → `getFieldName()` rename
  under the method-naming convention, see Conventions):
  - **Domain = backed public properties.** The hydrator acts as an
    ordinary external caller over stored state; direction of
    participation follows PHP visibility natively (readable by anyone →
    extracted, writable by anyone → hydrated; asymmetric visibility ⇒
    asymmetric participation is the user's responsibility). Virtual
    properties have no stored state → outside the domain: the
    virtual-with-set-hook hydration is REMOVED (the only place the
    engine actively opted into hook machinery; silent BC change — the
    field switches from required+consumed to ignored, changelog +
    migration pointer to CustomValue/TypeAdapter/Format subclass).
    Virtuals stay **mapped but inert** (slot exists, both directions
    false) so the engine can issue precise errors and keep the info for
    future use — but the mapping never legitimizes their fields as
    input: the hydrator behaves as if virtuals were not declared, so a
    data key matching a virtual's field is rejected by `rejectUnknown`
    like any unknown key (the slot knowledge only enriches the error
    message; in the default mode such a key is silently ignored like
    any extra field — accepting-and-discarding it would be worse than
    both). Side effect: closes the latent `keyBy` hole (a virtual-set
    int property passed eager checks, then crashed with a cryptic
    write-only Error — now rejected eagerly as non-writable).
  - **`isInitialized(Entity, string $property): bool` +
    `getInitializedPropertyNames(Entity): list<string>`** on Hydrator —
    hook-free stored-state introspection in property vocabulary (the
    application must never speak field names; `toData()` output is an
    opaque payload for the storage driver — the reason the workaround
    from issue #1 is invalid). The plural returns property NAMES only,
    deliberately no values (prevents misuse as a reader) — renamed
    from `initializedProperties` to kill the expectation of receiving
    the properties themselves ("keys" rejected too: in this library
    keys mean stream/array keys, which are property values). Backed properties (with or without
    hooks): `ReflectionProperty::isInitialized()`, hooks never invoked,
    no user code ever runs. Unknown property → MetadataException;
    virtual property → MetadataException with a human message plus an
    explicit addendum that virtual properties cannot be processed
    (virtuals have no stored state — asking is a caller bug, keyBy
    precedent). The plural silently skips virtuals (toData precedent).
    Today its result coincides with the set of properties `toData()`
    would emit, but the equivalence is deliberately NOT a contract —
    the future `#[Omitted]` will apply to extraction, not to state
    introspection.
    The promise is "stored value is initialized", NOT "reading is safe"
    (a backed get hook may still throw on its own uninitialized
    dependencies). Docs teach native idioms first: `isset()`/`??=` are
    safe on uninitialized typed properties and exact for non-nullable
    ones — the API is for nullable patch semantics (isset conflates
    set-null with unset), hooked entities and generic code. Probes
    2026-08-05 (PHP 8.4 + 8.5 identical, `tests/tmp/probe-*.php`):
    reflection `isInitialized()` on a virtual property always returns
    true (useless); `isset()` invokes get hooks and crashes on an
    uninitialized backing/dependency; reflection on backed hooked
    properties reads the backing without invoking hooks. Rejected on
    the way (do not re-propose): probe-by-read for virtuals with
    Error-message suffix matching (fragile, executes user code, has a
    write-only corner case); `toData()` + `array_key_exists` as the
    answer (breaks field-vocabulary isolation); an
    `initializedProperties()` switch to exclude virtuals (ignoring
    virtuals is unconditional).

## Future candidates (analyzed, not scheduled)

- **Metadata/slot cache** (analysis 2026-08-01, preparatory refactor
  deliberately deferred until the cache layer itself): `PropertySlot` is
  pure cacheable data EXCEPT `ReflectionProperty`, which runtime needs
  only for `isInitialized()` in toData. Reflection-free alternatives are
  disproved by probes — `get_object_vars()` invokes get hooks in PHP 8.4
  (crashes on an uninitialized hooked backing, computes virtuals), and
  `isset()` conflates initialized-null with uninitialized. Solution:
  lazy reflection re-attach (`new ReflectionProperty(class, name)` on
  slot load; `__serialize`/`__unserialize` skipping the reflection). No
  external API change needed — a future metadata-cache loader is an
  additive factory parameter. Cache key must be (entity class, format
  class, adapter-registration fingerprint) — slots carry format-resolved
  attributes and `CustomSpec::$adapterClass`. Invalidation (entity file
  mtime…) is the cache layer's concern. The adapter capability map is
  already cacheable by the `provides()` contract.
- **`adapterLoader` DI hook** — lazy adapter instantiation through a
  container callback; additive factory parameter (see the custom types
  decision).
- **DB-structure ↔ entity mapping validator** (idea 2026-08-01) — an
  integration-test-level tool comparing a live database schema with
  entity metadata; deliberately not a runtime feature.
- **`#[Omitted]` attribute** (agreed 2026-08-05, planned next extension
  after 0.6, not part of it) — excludes a backed public property from
  de/hydration. Definitely **format-scoped** (repeatable, same resolver
  and top-down first-match rules as `#[Name]`/`#[Fraction]`, catch-all
  default) — e.g. omit for database formats but emit into Json. It
  applies to extraction, NOT to state introspection — an omitted
  property still has stored state, so `isInitialized()`/
  `getInitializedPropertyNames()` keep answering for it (which is why
  the getInitializedPropertyNames ≡ toData equivalence is not a
  contract); the exact
  hydration-side semantics (is the field still required/consumed?) is
  an open question for its own design round. Use case: public backed
  app-only state (runtime cache, derived intermediate) that today
  forces a field in strict hydration; private/protected properties are
  already unmapped and need no attribute. Additive feature.
- Composite keys, more `Type\*` attributes as needed.

First real consumer: project Lexion (infosoud-checker; PHP 8.5,
nette/database) — see its `docs/roadmap.md`, section "Typové entity a
de/hydratace". Performance target: parity with the POC harness
(`bin/poc-hydration.php` in branch `poc-hydration` there, ~490k rows/s).
Local baseline (2026-07-29, dev container, full-type entity):
NetteDatabase ~322k rows/s hydrate, ~492k rows/s extract — an ad-hoc
bench script lives outside the repo (tests/tmp is gitignored).

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
- **Method naming** (decision 2026-08-05): every public method starts
  with a verb that categorizes the call — transformations use direction
  pairs (`fromData`/`toData`, `import*`/`export*`, `fromNative`/
  `toNative`), stream terminals use `collect*`, predicates use
  `is`/`has`, and plain data-returning getters carry `get`
  (`getFieldName()`, `getInitializedPropertyNames()`). A getter named
  as a bare noun is forbidden — it hides the call category and drifts
  toward PHP's "rubber" dual getter/setter functions
  (`error_reporting()` et al.), a trap once a setter counterpart is
  ever needed. `Format::fieldName()` was renamed to `getFieldName()`
  under this rule (pre-0.6, no external consumers).
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
