# Upgrading from v4.x to v5.0.0

Version `5.0.0` is a major release introducing automatic Doctrine DBAL-based schema synchronization for `_version` tables, a complete DDL & trigger overhaul, and improved CLI reporting. It intentionally drops backwards compatibility with v4 where required for reliability and simplicity.

---

## Step-by-Step Upgrade Checklist

1. **Verify minimum requirements:**
   - PHP `>= 8.4`
   - Symfony Console `^7.4 || ^8.0`
   - Doctrine DBAL `^4.5`
   - Doctrine ORM `^3.6`
2. **Update your `composer.json`:**
   ```console
   composer require netbrothers-gmbh/version-bundle:^5.0
   ```
3. **Review `config/packages/netbrothers_version.yaml`:**
   - If you previously copied `ignore_columns: ['id', 'version', 'created_at', 'updated_at']` from v4 examples, remove `created_at` and `updated_at` (or set `ignore_columns: []`) unless you intentionally want those columns excluded from `_version` tables and triggers.
4. **Verify Doctrine's `schema_filter`:**
   - Ensure `schema_filter: '~(?<!_version)$~'` is configured under `doctrine.dbal` (not `doctrine.orm`).
5. **Preview changes on your existing database first:**
   ```console
   php bin/console netbrothers:version --summary
   php bin/console netbrothers:version --sql
   ```
   - Expect one-time `ALTER TABLE *_version` statements relaxing existing v4 `NOT NULL` non-PK columns to `NULL` (see Breaking Changes below).
   - Expect `DROP TRIGGER IF EXISTS ..._BEVOR_INSERT_SET_VERSION` statements cleaning up legacy v4 triggers automatically.
6. **Run the synchronization once:**
   ```console
   php bin/console netbrothers:version
   ```
7. **Stop adding manual `ALTER TABLE *_version` statements to new Doctrine migrations:**
   - Going forward, let Doctrine migrations modify origin tables only, then run `php bin/console netbrothers:version`.

---

## Breaking Changes

### 1. Updated Minimum Requirements
- Minimum PHP version raised from `>= 8.2` to `>= 8.4`.
- Minimum `doctrine/dbal` version raised from `^4.4.1` to `^4.5` (v5 exclusively uses non-deprecated DBAL 4.5+ `introspectTables()`, `Table::editor()`, and `Column::editor()` APIs).

### 2. Automatic Schema Synchronization Replaces Error-on-Mismatch
- In v4, schema mismatches between origin and `_version` tables caused `CompareService` to abort with an error.
- In v5, `CompareService` has been removed and replaced by `VersionTableSchemaSyncer`. Running `netbrothers:version` automatically applies `ALTER TABLE` (`ADD`, `MODIFY`, `DROP`) statements to existing `_version` tables.
- **Data-loss notice:** Columns removed from an origin table are automatically **dropped** from the corresponding `_version` table (and orphaned `_version` tables without an origin table are dropped). Always preview with `--summary` or `--sql` if you need to inspect destructive changes first.

### 3. All Non-PK Columns in `_version` Tables Are Enforced as Nullable (`NULL`)
- Only composite primary key columns (`id` and `version`) are `NOT NULL` in `_version` tables.
- All other columns are maintained as nullable (`NULL`) so adding `NOT NULL` columns without defaults (`DATETIME`, `JSON`, `TEXT`) or tightening `NULL → NOT NULL` never fails on historical version rows.
- On the first v5 run against an existing v4 database, non-PK columns previously created as `NOT NULL` in `_version` will be altered to nullable once. Subsequent runs are idempotent and emit zero `ALTER TABLE` statements.

### 4. Changed `ignore_columns` Default and Semantics
- Previously, `VersionConfigService` hardcoded `DEFAULT_IGNORE_COLUMNS = ['id', 'version', 'created_at', 'updated_at']` and only skipped them during comparison.
- In v5, `ignore_columns` defaults to `[]` (empty), so `created_at` and `updated_at` are synchronized and versioned by default.
- Any non-PK column explicitly listed in `ignore_columns` (or `--exclude-column`) is consistently skipped in `_version` table creation, schema synchronization, and trigger `INSERT` statements. Primary key columns (`id`, `version`) are always protected.

### 5. Trigger Rename (`BEVOR_INSERT_SET_VERSION` → `BEFORE_INSERT_SET_VERSION`)
- `Definitions::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION` has been fixed from `'BEVOR_INSERT_SET_VERSION'` to `'BEFORE_INSERT_SET_VERSION'`.
- `DropTrigger` automatically emits `DROP TRIGGER IF EXISTS` for both the new name and the legacy `BEVOR_INSERT_SET_VERSION` name, so running `netbrothers:version` once after upgrading cleans up old v4 triggers automatically.

### 6. Deterministic 64-Character Identifier Shortening
- Version table names, trigger names, and foreign key constraint names exceeding MySQL/MariaDB's 64-character identifier limit are now deterministically shortened using an 8-character SHA-256 hash suffix.
- Tables with normal names (<= 36 characters for triggers, <= 46 for FKs, <= 56 for version tables) keep the exact same identifier names.

### 7. CLI Options & Output Changes
- The unused, redundant `--create-trigger` option has been removed (default invocation performs the full drop → create/sync → recreate-triggers workflow).
- Combining `--drop-trigger` with `--drop-version`, or `--sql` with `--summary`, now returns an error (`Command::FAILURE`).
- `--sql` now outputs clean, directly executable SQL to `stdout` (without decorative banners or statistics, and with `DELIMITER $$` / `DELIMITER ;` around trigger blocks) so it can be piped directly into a `.sql` file.
- Standalone options `--ignore-table` / `--exclude-column` now require values (`VALUE_REQUIRED | VALUE_IS_ARRAY`).

### 8. Removed Legacy ORM 2 Annotations & Internal Service Refactors
- Legacy `@ORM\Column` docblock annotations have been removed from `NetBrothers\VersionBundle\Traits\VersionColumn` (PHP 8 attributes remain).
- Internal services (`JobService`, `GenerateService`, `VersionTable`, `CreateTrigger`, `DropTrigger`, `ExecuteService`) have updated constructor and method signatures to remove eager database side-effects and use `DatabaseConnectionProvider`, `VersionTableSchemaBuilder`, `VersionTableSchemaSyncer`, and `IdentifierHelper`.
- Removed unused legacy methods (`JobService::hasWarning()`, `JobService::hasError()`, `JobService::getReport()`, `JobService::addTableName()`, `GenerateService::hasVersionTable()`, and `CompareService`).
