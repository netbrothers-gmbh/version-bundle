# Changelog

All notable changes to `netbrothers-gmbh/version-bundle` are documented in this file.

## [5.0.0] - 2026-11-24

### Breaking Changes
- Raised minimum requirements to **PHP `>= 8.4`** and **Doctrine DBAL `^4.5`**.
- Replaced error-on-mismatch `CompareService` with automatic Doctrine DBAL-based schema synchronization (`ADD`, `MODIFY`, `DROP`) for existing `_version` tables.
- Enforced all non-primary-key columns in `_version` tables as nullable (`NULL`) to preserve historical compatibility on strict-mode `ADD NOT NULL` and `NULL → NOT NULL` migrations.
- Columns dropped from origin tables (and orphaned `_version` tables without an origin table) are now dropped automatically.
- Removed hardcoded `DEFAULT_IGNORE_COLUMNS` (`['id', 'version', 'created_at', 'updated_at']`); `ignore_columns` now defaults to `[]` and consistently excludes listed non-PK columns from table creation, schema sync, and triggers.
- Renamed `Definitions::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION` from `BEVOR_INSERT_SET_VERSION` to `BEFORE_INSERT_SET_VERSION` (legacy triggers are dropped automatically on upgrade).
- Deterministically shorten version table, trigger, and foreign key identifiers exceeding MySQL/MariaDB's 64-character limit.
- Removed unused `--create-trigger` CLI option; rejected mutually exclusive `--drop-trigger` + `--drop-version` and `--sql` + `--summary` flags; changed `--sql` to emit pure executable SQL on `stdout`.
- Removed legacy Doctrine ORM 2 `@ORM\Column` docblock annotation from `VersionColumn` trait.
- Refactored internal service constructors and method signatures for lazy DB lifecycle and non-deprecated DBAL 4.5+ APIs.

See [UPGRADE-5.0.md](UPGRADE-5.0.md) for the complete upgrade guide.

### Added
- Automatic `_version` schema synchronization via `VersionTableSchemaBuilder`, `VersionTableSchemaSyncer`, and `TableSyncPlan` using Doctrine DBAL's `Comparator` and `AbstractPlatform::getAlterTableSQL()`.
- Deterministic 64-character identifier shortening via `IdentifierHelper`.
- Lazy `DatabaseConnectionProvider` using DBAL 4.5+ `introspectTables()` / `introspectTableByUnquotedName()` with `try / finally` restoration of `SchemaAssetsFilter`.
- Optional `--em` CLI option in `netbrothers:version` for Symfony applications with multiple Doctrine EntityManagers.
- Structured CLI reporting via `ConsoleReportRenderer` (per-table statuses, `+ ADD` / `~ MODIFY` / `- DROP` column diffs, data-loss warnings, mode-accurate counters).
- `SqlScriptFormatter` producing clean, pipeable SQL output with `DELIMITER $$` / `DELIMITER ;` blocks around trigger definitions.
- Comprehensive `--help` documentation for both Symfony and standalone commands.
- Full PHPUnit 12 unit and MariaDB 11.4 / MySQL 8.4 integration test suite, PHPStan 2.x at level 8 with deprecation rules, `compose.yaml`, and modernized GitHub Actions CI matrix (PHP 8.4 & 8.5 × MariaDB 11.4 & MySQL 8.4).
- Dedicated [UPGRADE-5.0.md](UPGRADE-5.0.md) guide.

### Changed
- Replaced `CREATE TABLE ... AS SELECT *` with DBAL-generated `CREATE TABLE` DDL plus safe `UPDATE ... SET version = 1 WHERE version IS NULL` and explicit column backfill.
- Hardened `BEFORE UPDATE` trigger to use `COALESCE(OLD.version, 0) + 1`.
- Applied DBAL platform identifier quoting consistently across all generated DDL, DML, and trigger statements (supporting reserved SQL keywords).
- Updated `bin/netbrothers-version` for Symfony Console 7.4 / 8.x (`Application::addCommand()`, single-command mode, dynamic version via `Composer\InstalledVersions`).
- Updated `README.md`, `doc/index.md`, and `doc/packages/netbrothers_version.yaml` (fixing `schema_filter` placement under `doctrine.dbal`).

### Fixed
- Fixed single-table execution (`netbrothers:version <tableName>`) previously producing zero SQL queries.
- Fixed standalone CLI crashes caused by forwarding standalone-only options (`--container-file`, `--ignore-table`, `--exclude-column`) to `MakeVersionCommand`.
- Fixed `--drop-trigger` and `--drop-version` execution and orphaned `_version` table cleanup.
- Fixed `bin/netbrothers-version` compatibility with Symfony 8.0 / 8.1.

---

## [4.0.0] - 2026-02-02
- Refactoring for Symfony 7.4 and Symfony 8.
- Updated PHP requirements to `>= 8.4`.
- Updated documentation.
- `[BC BREAK]` All service and command classes are now marked as `final`.

## [3.0.0] - 2024-05-29
- Updated Symfony requirements to `>= 7 < 8`.
- Updated PHP requirements to `>= 8.2`.

## [2.0.0] - 2023-12-06
- Fixed deprecation since `symfony/console` 6.1 regarding static `$defaultName`.
- Updated Symfony requirements to `>= 6 < 7`.
- Updated PHP requirements to `>= 8.1`.

## [0.0.4] - 2022-02-18
- Fixed bug "There is no active transaction".

## [0.0.3] - 2022-02-03
- Added `?ExtensionInterface` native return type declaration in `NetBrothersVersionBundle`.
- Updated PHP requirements in `composer.json` to `>= 7.4`.

## [0.0.2] - 2021-11-15
- Fixed issue [#1](https://github.com/netbrothers-gmbh/version-bundle/issues/1) (deprecated Doctrine statement in `ExecuteService`).

## [0.0.1]
- First rollout.
