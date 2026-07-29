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
- **ValueKind** (`src/Metadata/`) — logical type resolved once from the PHP
  type + refining attributes (`#[Type\Date]`): Int, Float, String, Bool,
  Enum, DateTime, Date, Interval, Mixed.
- **PropertySlot** — precomputed per-property plan for one
  (entity class, format) pair; built once, cached in the `Hydrator`
  instance. `HydratorFactory` caches hydrators per (class, format).

## Load-bearing design decisions

- **Stream-first, no data caching.** The library never caches data or
  entities — that is the application's domain. `fromDataSet()` returns a
  lazy single-pass `Generator`. The only caches are entity metadata and
  compiled plans.
- **Attribute evaluation order**: `#[Name]` is repeatable, evaluated
  top-down, first match wins (like `match`); scopes match via `instanceof`
  (concrete class, ancestor or family interface) — so a format subclass
  inherits scopes of its parents. An attribute declared after an unscoped
  catch-all → `MetadataException` (unreachable). Scopes are typed
  `class-string<FormatScope>` — a marker interface implemented by `Format`
  and extended by family interfaces (`DatabaseFormat`); custom family
  interfaces must extend `FormatScope` too.
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
- **Strictness**: missing field, null into non-nullable, wrong value type →
  `HydrationException` with `Class::$property` + field context. Value
  codecs in formats throw `ValueException`; the engine wraps it with
  context. Union/intersection types, mutable `DateTime`, unknown attribute
  scopes → `MetadataException` at metadata-build time.
- **Versioning 0.x** until the API stabilizes; breaking changes allowed in
  0.x. No `v1.0` until the design settles with real consumers.

## Roadmap (agreed 2026-07-29)

- **0.1** — current scope: engine, `NetteDatabase` + `Mysql` formats,
  `#[Name]` + `#[Type\Date]`, streaming data sets.
- **0.2** — `Json` format (camelCase convention, date-time ↔ RFC 3339 with
  foreign-offset recalculation, date ↔ `Y-m-d`; interval representation to
  be decided there).
- **0.3** — nested structs (per-format contract: string in DB, nested
  object in JSON) and a general extension point for custom value types.
- Later: composite keys, more `Type\*` attributes as needed.

First real consumer: project Lexion (infosoud-checker; PHP 8.5,
nette/database) — see its `docs/roadmap.md`, section "Typové entity a
de/hydratace". Performance target: parity with the POC harness
(`bin/poc-hydration.php` in branch `poc-hydration` there, ~490k rows/s).

## Conventions

- Scaffold follows `jakubboucek/nette-http-request-strict-proxy`: Nette
  Tester (`.phpt` + `test()` function), PHPStan `level: max`
  (`treatPhpDocTypesAsCertain: false` — runtime guards on PHPDoc-certain
  types are intentional), no coding-standard tool, `code_analysis.yaml`
  workflow, `.gitattributes` export-ignore, MIT.
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
