# NetBrothers VersionBundle Documentation

This document provides a complete guide to installing, configuring, and using `netbrothers-gmbh/version-bundle` v5.0, both as a Symfony bundle and as a standalone PHP CLI package.

> **Important:** This package is designed specifically for **MariaDB and MySQL**. The generated trigger syntax is not compatible with PostgreSQL or SQLite.

---

## 1. How It Works

For every origin table (e.g., `protocol`) containing a `version` column, the bundle manages:

1. **Version Table (`protocol_version`):**
   - Created via Doctrine DBAL DDL (`CREATE TABLE`) with a composite primary key `(id, version)` (or `(<origin_pk_columns>, version)`), without `AUTO_INCREMENT`, and an `ON DELETE CASCADE` foreign key referencing the origin table.
   - Origin secondary/unique indexes and origin foreign keys are intentionally not mirrored.
   - Existing rows are safely backfilled (`UPDATE ... SET version = 1 WHERE version IS NULL` followed by an explicit `INSERT INTO ... SELECT ...`).
2. **Automatic Schema Synchronization:**
   - Whenever an existing origin table changes via a Doctrine migration, running `netbrothers:version` compares the existing `_version` table against the expected schema derived from the origin table using Doctrine DBAL's `Comparator` and generates native `ALTER TABLE` statements (`ADD`, `MODIFY`, `DROP`).
   - Running the command repeatedly on an already synchronized database emits zero `ALTER TABLE` statements (idempotent).
3. **Versioning Triggers:**
   - `BEFORE INSERT` (`*_BEFORE_INSERT_SET_VERSION`): sets `NEW.version = 1`.
   - `AFTER INSERT` (`*_AFTER_INSERT_INSERT_VERSION`): inserts the initial snapshot into `_version`.
   - `BEFORE UPDATE` (`*_BEFORE_UPDATE_SET_VERSION`): sets `NEW.version = COALESCE(OLD.version, 0) + 1`.
   - `AFTER UPDATE` (`*_AFTER_UPDATE_INSERT_VERSION`): inserts the updated snapshot into `_version`.
   - Legacy v4 `*_BEVOR_INSERT_SET_VERSION` triggers are dropped automatically via `DROP TRIGGER IF EXISTS`.
4. **MySQL/MariaDB 64-Character Identifier Limit:**
   - Whenever a generated version table, trigger, or foreign key name exceeds 64 characters, it is deterministically shortened with an 8-character SHA-256 hash suffix.

### Design Rules for `_version` Tables

- **All non-primary-key columns are nullable (`NULL`):**
  Only the composite primary key columns (`id` and `version`) are `NOT NULL`. This ensures that adding `NOT NULL` columns without defaults (such as `DATETIME NOT NULL`, `JSON NOT NULL`, or `LONGTEXT NOT NULL`) or tightening an existing column from `NULL` to `NOT NULL` never fails against historical rows in `_version`. Data integrity remains enforced on the origin table.
- **Removed columns are dropped automatically:**
  When a column is dropped from an origin table, the next sync run drops it from the corresponding `_version` table as well (permanently removing historical values for that column). Dropped columns are prominently highlighted with a warning in CLI output and `--summary`.
- **Orphaned `_version` tables are dropped:**
  If an origin table is removed completely, its orphaned `_version` table is flagged as `ORPHANED` and dropped on sync (or `--drop-version`).
- **`ignore_columns` semantics:**
  By default, `ignore_columns` is empty (`[]`) — every column on a versioned origin table (including `created_at` and `updated_at`) is synchronized and recorded by triggers. Columns explicitly listed in `ignore_columns` (or `--exclude-column`) are consistently skipped during table creation, schema synchronization, and trigger generation. Primary key columns (`id`, `version`) are always protected and cannot be ignored or dropped.

---

## 2. Recommended Migration Workflow

Doctrine migrations should **only** modify your origin tables (never add manual `ALTER TABLE *_version` statements):

```console
# 1. Apply Doctrine migrations
php bin/console doctrine:migrations:migrate

# 2. Drop triggers, create/sync `_version` tables, recreate triggers
php bin/console netbrothers:version
```

### Data-Migration Edge Case

For pure schema migrations (`ALTER TABLE ADD/MODIFY/DROP`), MySQL/MariaDB triggers do not fire and the 2-step workflow above works directly.

However, if a Doctrine migration alters a versioned table **and** executes `INSERT` or `UPDATE` statements on that table within the same migration (e.g., data backfill after dropping or modifying columns), drop triggers beforehand:

```console
php bin/console netbrothers:version --drop-trigger
php bin/console doctrine:migrations:migrate
php bin/console netbrothers:version
```

---

## 3. Preparing Your Entities

Add a `version` column (`INT` or `BIGINT`) to every entity you want versioned, for example using the bundled `VersionColumn` trait:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use NetBrothers\VersionBundle\Traits\VersionColumn;

#[ORM\Entity]
class Protocol
{
    use VersionColumn;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // ...
}
```

---

## 4. Usage as a Symfony Bundle

### 1. Install the bundle

```console
composer require netbrothers-gmbh/version-bundle
```

### 2. Register the bundle (if not using Symfony Flex)

```php
// config/bundles.php
return [
    // ...
    NetBrothers\VersionBundle\NetBrothersVersionBundle::class => ['all' => true],
];
```

### 3. Configure Doctrine `schema_filter`

Instruct Doctrine DBAL to ignore `*_version` tables so `doctrine:migrations:diff` does not attempt to drop them. Place `schema_filter` under **`doctrine.dbal`** (not `doctrine.orm`):

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        schema_filter: '~(?<!_version)$~'
```

See [doctrine_example.yaml](packages/doctrine_example.yaml) for single- and multi-connection examples.

### 4. Configure the bundle (optional)

Copy or create `config/packages/netbrothers_version.yaml` (see [netbrothers_version.yaml](packages/netbrothers_version.yaml)):

```yaml
# config/packages/netbrothers_version.yaml
netbrothers_version:
    ignore_tables:
        - 'doctrine_migration_versions'
    ignore_columns: []
```

### 5. Run the command

```console
# Synchronize all tables and recreate triggers
php bin/console netbrothers:version

# Synchronize only a single origin table
php bin/console netbrothers:version protocol

# Dry-run: display structured table plan, column diffs, warnings, and counters
php bin/console netbrothers:version --summary

# Dry-run: output clean, directly executable SQL (with DELIMITER blocks)
php bin/console netbrothers:version --sql > version_sync.sql

# Drop triggers only (keep `_version` tables and history)
php bin/console netbrothers:version --drop-trigger

# Drop triggers and all `_version` tables
php bin/console netbrothers:version --drop-version

# Target a specific Doctrine EntityManager (multi-connection apps)
php bin/console netbrothers:version --em=secondary
```

---

## 5. Usage as a Standalone Package

The standalone binary `vendor/bin/netbrothers-version` provides identical schema synchronization, trigger generation, reporting, and `--sql` dry-run capabilities without requiring Symfony FrameworkBundle.

### 1. Create a PSR-11 container file

Return a [PSR-11 `ContainerInterface`](https://www.php-fig.org/psr/psr-11/) that provides `Doctrine\ORM\EntityManagerInterface`:

```php
<?php

declare(strict_types=1);

// config/container.php
require_once __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions([
    EntityManagerInterface::class => static function (): EntityManagerInterface {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'dbname' => 'app',
            'user' => 'app',
            'password' => 'secret',
            'charset' => 'utf8mb4',
        ]);

        $ormConfig = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../src/Entity'], true);
        $ormConfig->enableNativeLazyObjects(true);

        return new EntityManager($connection, $ormConfig);
    },
]);

return $containerBuilder->build();
```

### 2. Run the standalone CLI

```console
# Synchronize all tables
vendor/bin/netbrothers-version --container-file=config/container.php

# Synchronize a single table
vendor/bin/netbrothers-version -c config/container.php protocol

# Dry-run summary or clean SQL export
vendor/bin/netbrothers-version -c config/container.php --summary
vendor/bin/netbrothers-version -c config/container.php --sql > version_sync.sql

# Ignore specific tables or exclude non-PK columns (repeatable)
vendor/bin/netbrothers-version -c config/container.php -i doctrine_migration_versions -i audit_log -x cached_blob

# Drop triggers or drop version tables
vendor/bin/netbrothers-version -c config/container.php --drop-trigger
vendor/bin/netbrothers-version -c config/container.php --drop-version
```

---

## 6. CLI Reference

### Arguments

| Argument | Description |
| --- | --- |
| `tableName` *(optional)* | Process only this single origin table. |

### Shared Options (Symfony & Standalone)

| Option | Description |
| --- | --- |
| `--summary` | Dry-run: display structured per-table plan, column changes (`+ ADD`, `~ MODIFY`, `- DROP`), warnings, and counters without executing SQL. |
| `--sql` | Dry-run: write clean, directly executable SQL (including `DELIMITER $$` blocks for triggers) to `stdout` without modifying the database. Cannot be combined with `--summary`. |
| `--drop-trigger` | Drop versioning triggers only (preserves `_version` tables). Cannot be combined with `--drop-version`. |
| `--drop-version` | Drop versioning triggers and `_version` tables (including orphaned `_version` tables). Cannot be combined with `--drop-trigger`. |
| `-h`, `--help` | Display full help text and examples. |

### Symfony-Only Options

| Option | Description |
| --- | --- |
| `--em=NAME` | Select a specific Doctrine EntityManager by name. |

### Standalone-Only Options

| Option | Description |
| --- | --- |
| `-c`, `--container-file=PATH` | *(Required)* Path to a PHP file returning a PSR-11 container with `EntityManagerInterface`. |
| `-i`, `--ignore-table=TABLE` | Table name to ignore (repeatable). |
| `-x`, `--exclude-column=COLUMN` | Non-PK column name to exclude from versioning (repeatable). |

---

## 7. Running Tests & Static Analysis (Contributing)

```console
# Static analysis (PHPStan level 8 + deprecation rules)
composer run static-analysis

# Unit tests
composer run test:unit

# Integration tests against MariaDB 11.4 / MySQL 8.4 (requires Docker or a local DB)
docker compose up -d
TEST_DATABASE_URL="pdo-mysql://root:root@127.0.0.1:3307/version_bundle_test" composer run test:integration
TEST_DATABASE_URL="pdo-mysql://root:root@127.0.0.1:3308/version_bundle_test" composer run test:integration
```
