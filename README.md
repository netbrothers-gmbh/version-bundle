![build status](https://github.com/netbrothers-gmbh/version-bundle/actions/workflows/build.yml/badge.svg)

# NetBrothers Version Bundle

This hybrid package works as a [Symfony bundle](https://symfony.com/doc/current/bundles.html)
or as a standalone PHP package for managing versioned tables in [MariaDB](https://mariadb.com/)
and [MySQL](https://www.mysql.com/) databases. It makes use of the
[Doctrine ORM](https://www.doctrine-project.org/projects/orm.html) to interact
with your database.

__NOTE__: This package is designed to work with MariaDB/MySQL. It is **not**
considered to work with other RDBMS.

In essence, this package provides one command which does two things.

1. Create Version Tables  
For tables (e.g. `orig_table`) with a column named `version` of type
`INT`/`BIGINT` the script will create a corresponding version table (e.g.
`orig_table_version`) so that origin and version tables are structurally identical.

2. Create Version Triggers  
For every versioned origin table the script creates a trigger, which will on
`INSERT`s and `UPDATE`s increase the version number in the version column and
save a copy of the row in the version table.

## Installation

On the command prompt, change into your project's root directory and execute:

```console
composer require netbrothers-gmbh/version-bundle
```

There are three installation variants:

### Standalone Package

No further installation steps are necessary.
### Symfony Bundle with Flex

No further installation steps are necessary. Symfony Flex will automatically
register the bundle in `config/bundles.php`.
### Symfony Bundle without Flex

You have to enable the bundle by adding it to the list of registered bundles in
the file `config/bundles.php` in your project.

```php
// config/bundles.php
return [
    // ...
    NetBrothers\VersionBundle\NetBrothersVersionBundle::class => ['all' => true],
    // ...
];
```

## Configuration

### Symfony Bundle

Copy the file [netbrothers_version.yaml](install/config/packages/netbrothers_version.yaml)
from the `install` folder of this package to your Symfony project's config path.

#### **Doctrine Migrations**

If you are using [Doctrine Migrations](https://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html)
instruct it to ignore your version tables, by using/customizing the
[schema filter](https://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html#manual-tables) option.
If you don't have any other schema filter, you might use this:
`schema_filter: ~(?<!_version)$~`. See in the [example file](install/config/packages/doctrine_example.yaml)
how it's done.

__NOTE__: If you don't filter your version tables, Doctrine may drop them on the
next occasion.

#### **Bundle Configuration**

You can specify certain columns (by name) to always be ignored by the compare
algorithm when creating versions. See how it's done in the example file
[`netbrothers_version.yaml`](install/config/packages/netbrothers_version.yaml).

### Standalone

In most PHP frameworks you will have a [PSR-11 compatible container](https://php-di.org/)
to manage your dependencies. You'll have to provide this container to the script
via a file argument.

```console
vendor/bin/netbrothers-version --container-file=config/container.php --summary
```

The script will check if the provided container implements the
[PSR-11 `ContainerInterface`](https://github.com/php-fig/container/blob/master/src/ContainerInterface.php).
If it does, it will assume an instance of the
[Doctrine EntityManagerInterface](https://github.com/doctrine/orm/blob/2.8.x/lib/Doctrine/ORM/EntityManagerInterface.php)
by the identifier `EntityManagerInterface::class`. Here's an example on how to check,
if your container file works properly.

```php
<?php

require_once '/path/to/vendor/autoload.php';

use Psr\Container\ContainerInterface;
use Doctrine\ORM\EntityManagerInterface;

$container = require '/path/to/your/container-file.php';

if (
    $container instanceof ContainerInterface
    && $container->get(EntityManagerInterface::class) instanceof EntityManagerInterface
) {
    // everything is fine
} else {
    // you need to check your container file
}
```

In standalone mode, ignoring tables and columns is controlled by command
line options.

```console
vendor/bin/netbrothers-version \
    --container-file=config/container.php \
    --ignore-table=unversioned_table_one \
    --ignore-table=unversioned_table_two \
    --exclude-column=unversioned_column_one \
    --exclude-column=unversioned_column_two \
    --create-trigger
```

## Usage

### Prepare your Entities/Origin Tables

Add a column named `version` (type `INT`/`BIGINT`) to every table you want to
be versioned. This can be done by adding the Trait
[VersionColumn](src/Traits/VersionColumn.php) to your entities and then creating
and applying a migration.

### Create Version Tables and Triggers

Issue the following command.

```console
# Symfony
bin/console netbrothers:version 

# Standalone
vendor/bin/netbrothers-version --container-file=config/container.php
```

For every table with a `version`-column the command will

- create a corresponding version table (if it doesn't exist yet),
- compare the columns in both tables and alter the version table to match
  the origin table,
- (if present) it will drop the old version triggers and
- (in any case) it will create the version triggers.

### Create Version Tables and Triggers for a Single Table

If needed, you can apply the versioning to a single table. This can be done by
providing the table name as an argument to the console command.

```console

# Symfony
bin/console netbrothers:version [<tableName>]

# Standalone
vendor/bin/netbrothers-version --container-file=config/container.php [<tableName>]
```

### Command Line Options

The version command provides these options (sub commands).

| Option             | Meaning                                                      |
| -------------      | ------------------------------------------------------------ |
| `--create-trigger` (default)  | drop triggers, create non-existent version tables, recreate triggers |
| `--drop-trigger`   | drop triggers                                                |
| `--drop-version`   | drop triggers, drop version tables                           |
| `--sql`            | print the SQL statements without doing anything              |
| `--summary`        | print a human readable summary of what the command would do  |

## Licence

MIT

## Authors

- [Stefan Wessel, NetBrothers GmbH](https://netbrothers.de)
- [Thilo Ratnaweera, NetBrothers GmbH](https://netbrothers.de)

[![nb.logo](https://netbrothers.de/wp-content/uploads/2020/12/netbrothers_logo.png)](https://netbrothers.de)
