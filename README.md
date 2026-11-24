# NetBrothers Version Bundle

<p>
    <a href="https://packagist.org/packages/netbrothers-gmbh/version-bundle"><img src="https://img.shields.io/packagist/v/netbrothers-gmbh/version-bundle" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/netbrothers-gmbh/version-bundle"><img src="https://img.shields.io/packagist/dt/netbrothers-gmbh/version-bundle" alt="Total Downloads"></a>
    <a href="https://github.com/netbrothers-gmbh/version-bundle/blob/main/LICENSE"><img src="https://img.shields.io/github/license/netbrothers-gmbh/version-bundle" alt="License"></a>
    <a href="https://github.com/netbrothers-gmbh/version-bundle/actions/workflows/build.yml"><img src="https://github.com/netbrothers-gmbh/version-bundle/actions/workflows/build.yml/badge.svg" alt="Build Status"></a>
</p>

This hybrid package works as a [Symfony bundle](https://symfony.com/doc/current/bundles.html) or as a standalone PHP CLI tool for trigger-based row versioning in **MariaDB** and **MySQL** databases using Doctrine.

It automatically creates `_version` tables, synchronizes their schemas when your origin tables evolve through Doctrine migrations, and maintains `INSERT` / `UPDATE` triggers that record every revision.

> **Note:** This package is designed specifically for **MariaDB and MySQL**. It is **not** compatible with PostgreSQL or SQLite.

## Features

- **Automatic `_version` schema synchronization** via Doctrine DBAL — detects and applies `ADD`, `MODIFY`, and `DROP` column changes without requiring manual `ALTER TABLE *_version` statements in Doctrine migrations.
- **Historical-safe nullable columns** — keeps all non-primary-key columns nullable in `_version` tables so adding `NOT NULL` columns without defaults (`DATETIME`, `JSON`, `TEXT`) or tightening `NULL → NOT NULL` never breaks on existing version history.
- **Robust DDL & triggers** — DBAL-generated `CREATE TABLE` DDL with safe `NULL`-version backfill, `COALESCE(OLD.version, 0) + 1` increments, reserved SQL keyword quoting, and deterministic 64-character MySQL/MariaDB identifier shortening.
- **Transparent CLI UX** — structured per-table report, data-loss warnings for dropped columns, `--summary` dry-run, clean pipeable `--sql` output (with `DELIMITER $$` blocks), single-table execution, and multi-EntityManager `--em` support.
- **Dual mode** — works as a native Symfony bundle and as a standalone binary (`vendor/bin/netbrothers-version`) with any PSR-11 container.

## Requirements

- PHP `>= 8.4`
- Symfony Console `^7.4 || ^8.0`
- Doctrine DBAL `^4.5`
- Doctrine ORM `^3.6`
- MariaDB or MySQL

## Installation

```console
composer require netbrothers-gmbh/version-bundle
```

## Standard Migration Workflow

Doctrine migrations only need to modify your origin tables (exclude `*_version` tables via `doctrine.dbal.schema_filter: '~(?<!_version)$~'`):

```console
# 1. Apply Doctrine migrations (origin tables only)
php bin/console doctrine:migrations:migrate

# 2. Synchronize `_version` tables and recreate triggers
php bin/console netbrothers:version
```

Preview planned changes or export an executable SQL script without modifying the database:

```console
php bin/console netbrothers:version --summary
php bin/console netbrothers:version --sql > version_sync.sql
```

### Standalone Mode

```console
vendor/bin/netbrothers-version --container-file=config/container.php
```

## Documentation

- **[Full Documentation](doc/index.md)** — installation, configuration, automatic schema sync details, CLI reference, data-migration edge case, and standalone setup.
- **[Upgrading from v4 to v5](UPGRADE-5.0.md)** — complete guide to all breaking changes and migration steps.
- **[Changelog](CHANGELOG.md)** — release history.

## License

This bundle is released under the MIT license. See the bundled [LICENSE](LICENSE) file for details.

## Authors

- [Stefan Wessel, NetBrothers GmbH](https://netbrothers.de)
- [Thilo Ratnaweera, NetBrothers GmbH](https://netbrothers.de)

[![NetBrothers Logo](https://netbrothers.de/wp-content/uploads/2020/12/netbrothers_logo.png)](https://netbrothers.de)
