# jakubboucek/hydrator — project notes

Fast bidirectional hydrator between typed PHP entities and data records
(database rows, raw PDO rows, …). Extracted and generalized from the
hand-written `Hydrator` in the skradbuza.cz project and the POC port in
infosoud-checker (branch `poc-own-hydrator`).

## Development environment — Docker only

Never run PHP, Composer or MySQL locally on this machine — always through
Docker (`jakubboucek/lamp-devstack-php`, see docker-compose.yml):

```shell
docker compose up -d
docker compose exec php composer install
docker compose exec php composer test
docker compose exec php composer phpstan
```

GitHub Actions run natively (no Docker needed there).

## Architecture and terminology

- **Entity** — any plain object with typed public properties; no base class,
  no required attributes. Must be constructible without arguments.
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
  catch-all → `MetadataException` (unreachable).
- **Time zone is an application property** (factory-level, not per-format):
  every hydrated date-time is normalized into it (instances converted,
  naive strings interpreted, foreign offsets recalculated). Defaults to
  `date_default_timezone_get()`. Extraction formats strings in it;
  pass-through formats hand instances back unchanged.
- **Partial-update semantics**: extraction skips uninitialized properties
  (initialized null → field null). Completeness is a separate explicit
  check (`isComplete()`/`validate()` + `SelfValidating` interface), never
  enforced by hydration/extraction.
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
  `#[Name]` + `#[Type\Date]`, streaming data sets, validation.
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
- README in English, no badges, GitHub alert blockquotes.
