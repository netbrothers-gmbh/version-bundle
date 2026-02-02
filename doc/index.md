# NetBrothers VersionBundle Documentation

This document provides a detailed guide to installing, configuring, and using the NetBrothersVersionBundle, both as a Symfony bundle and as a standalone package.

> **Important**: This package is designed to work specifically with **MariaDB/MySQL**. The generated SQL for triggers is not compatible with other database systems like PostgreSQL or SQLite.

## 1. General Information

This package provides a command to manage versioned tables in MariaDB and MySQL databases.

1.  **Create Version Tables**: For tables (e.g., `orig_table`) with a column named `version`, the script creates a corresponding version table (e.g., `orig_table_version`) with an identical structure.
2.  **Create Version Triggers**: For every versioned origin table, the script creates triggers that, on `INSERT`s and `UPDATE`s, increase the version number and save a copy of the row in the version table.

### Preparing your Entities/Origin Tables

Add a column named `version` (type `INT` or `BIGINT`) to every table you want to be versioned. This can be done by adding the `VersionColumn` Trait to your entities and then creating and applying a Doctrine migration.

```php
// src/Entity/YourEntity.php
namespace App\Entity;

use NetBrothers\VersionBundle\Traits\VersionColumn;

class YourEntity
{
    use VersionColumn;
    
    // ... your other properties
}
```

### Command Line Options

The version command provides these options, which are available in both Symfony and standalone mode.

| Option             | Meaning                                                      |
| ------------------ | ------------------------------------------------------------ |
| `--create-trigger` (default) | Drop triggers, create non-existent version tables, recreate triggers. |
| `--drop-trigger`   | Drop triggers only.                                          |
| `--drop-version`   | Drop triggers and version tables.                            |
| `--sql`            | Print the SQL statements to the console without executing them. |
| `--summary`        | Print a human-readable summary of what the command would do. |

---

## 2. Usage as a Symfony Bundle

### Quick Start

1.  **Add a `version` column** to your entity using the provided trait.
2.  **Run the command** to generate version tables and triggers:
    ```console
    php bin/console netbrothers:version
    ```

### Detailed Installation & Configuration

**1. Install the bundle:**
```console
composer require netbrothers-gmbh/version-bundle
```

**2. Enable the Bundle (if not using Symfony Flex):**
Add the bundle to `config/bundles.php`:
```php
// config/bundles.php
return [
    // ...
    NetBrothers\VersionBundle\NetBrothersVersionBundle::class => ['all' => true],
];
```

**3. Copy the Configuration File:**
Copy the example configuration file to your project's config directory.
```console
cp vendor/netbrothers-gmbh/version-bundle/doc/packages/netbrothers_version.yaml config/packages/
```

**4. Configure Doctrine Migrations:**
You **must** instruct Doctrine to ignore your version tables to prevent them from being dropped. Add a `schema_filter` to your Doctrine configuration.

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        # ...
    orm:
        # ...
        schema_filter: '~(?<!_version)$~'
```
For a more detailed example, see the [doctrine_example.yaml](packages/doctrine_example.yaml) file.

**5. Configure the Bundle:**
You can specify tables or columns to be ignored in `config/packages/netbrothers_version.yaml`.

```yaml
# config/packages/netbrothers_version.yaml
netbrothers_version:
    ignore_tables:
        - 'table_to_ignore'
    ignore_columns:
        - 'column_to_ignore'
```

### Usage

Run the command using the Symfony Console:

```console
# To process all tables
php bin/console netbrothers:version

# To process a single table
php bin/console netbrothers:version <tableName>
```

---

## 3. Usage as a Standalone Package

### Quick Start

1.  **Create a container file** (e.g., `config/container.php`) that returns your PSR-11 container with a configured Doctrine `EntityManagerInterface`.
2.  **Run the command** and pass the container file path:
    ```console
    vendor/bin/netbrothers-version --container-file=config/container.php
    ```

### Detailed Installation & Configuration

**1. Install the package:**
```console
composer require netbrothers-gmbh/version-bundle
```

**2. Configure the Container:**
The standalone script requires a PHP file that returns a [PSR-11 compatible container](https://www.php-fig.org/psr/psr-11/). The script will fetch the Doctrine `EntityManagerInterface` from this container.

**Example `config/container.php`:**
```php
<?php
// config/container.php

require_once '/path/to/vendor/autoload.php';

use Doctrine\ORM\EntityManager;
use DI\ContainerBuilder; // Example using PHP-DI

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions([
    EntityManagerInterface::class => function() {
        // ... your Doctrine EntityManager setup
        return EntityManager::create($dbParams, $config);
    },
]);

return $containerBuilder->build();
```

### Usage

Run the command from the `vendor/bin` directory, providing the path to your container file. All configurations are passed as command-line options.

```console
# Basic usage
vendor/bin/netbrothers-version --container-file=config/container.php

# With ignore options
vendor/bin/netbrothers-version \
    --container-file=config/container.php \
    --ignore-table=unversioned_table_one \
    --ignore-table=unversioned_table_two \
    --exclude-column=unversioned_column_one \
    --exclude-column=unversioned_column_two
```
