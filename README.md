# NetBrothers Version Bundle

<p>
    <a href="https://packagist.org/packages/netbrothers-gmbh/version-bundle"><img src="https://img.shields.io/packagist/v/netbrothers-gmbh/version-bundle" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/netbrothers-gmbh/version-bundle"><img src="https://img.shields.io/packagist/dt/netbrothers-gmbh/version-bundle" alt="Total Downloads"></a>
    <a href="https://github.com/netbrothers-gmbh/version-bundle/blob/master/LICENSE"><img src="https://img.shields.io/github/license/netbrothers-gmbh/version-bundle" alt="License"></a>
    <a href="https://github.com/netbrothers-gmbh/version-bundle/actions/workflows/build.yml"><img src="https://github.com/netbrothers-gmbh/version-bundle/actions/workflows/build.yml/badge.svg" alt="Build Status"></a>
</p>

This hybrid package works as a [Symfony bundle](https://symfony.com/doc/current/bundles.html) or as a standalone PHP package for managing versioned tables in MariaDB and MySQL databases using Doctrine. It automatically creates versioning triggers for your database tables.

> **Note**: This package is designed to work specifically with **MariaDB/MySQL**. It is **not** compatible with other database systems like PostgreSQL or SQLite.

## Features

-   Creates and maintains `_version` tables for your Doctrine entities.
-   Automatically generates `INSERT` and `UPDATE` triggers.
-   Works as a native Symfony Bundle.
-   Works as a standalone package in any PHP project with a PSR-11 container.
-   Compatible with Symfony 7.4 / 8.x and Doctrine DBAL 4.

## Installation

```console
composer require netbrothers-gmbh/version-bundle
```

## Quick Start (Symfony Bundle)

1.  **Add a `version` column** to your entity using the provided trait.
2.  **Run the command** to generate version tables and triggers:
    ```console
    php bin/console netbrothers:version
    ```

## Quick Start (Standalone Package)

1.  **Create a container file** (e.g., `config/container.php`) that returns your PSR-11 container with a configured Doctrine `EntityManagerInterface`.
2.  **Run the command** and pass the container file path:
    ```console
    vendor/bin/netbrothers-version --container-file=config/container.php
    ```

## Documentation

For detailed instructions on installation, configuration, and usage (both for Symfony and standalone mode), please see the **[full documentation](doc/index.md)**.

## License

This bundle is released under the MIT license. See the bundled [LICENSE](LICENSE) file for details.

## Authors

-   [Stefan Wessel, NetBrothers GmbH](https://netbrothers.de)
-   [Thilo Ratnaweera, NetBrothers GmbH](https://netbrothers.de)

[![NetBrothers Logo](https://netbrothers.de/wp-content/uploads/2020/12/netbrothers_logo.png)](https://netbrothers.de)
