# NetBrothers Version Bundle

This hybrid package works as a [Symfony bundle](https://symfony.com/doc/current/bundles.html)
or as a standalone PHP package for managing versioned tables in [MariaDB](https://mariadb.com/)
and [MySQL](https://www.mysql.com/) databases.

It makes use of the [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html)
to interact with your database.

__NOTE__: This package doesn't necessarily work with RDBMS other than MySQL/MariaDB.

In essence, this package provides one command which does two things.

### Create Version Tables

For tables (e.g. `src_table`) with a column named `version` of type `INT`/`BIGINT`
the script will create a corresponding version table (e.g. `src_table_version`)
having identical columns.

### Create Version Triggers

For every versioned origin table the script creates a trigger, which will
increase the version number in the version column on updates and saves a copy
in the version table.

## Prerequisites

[Composer](https://getcomposer.org/) must be available on the command line prompt.

## Installation

Open a command prompt, change into your project's root directory and execute
the command.

```console
composer require netbrothers-gmbh/version-bundle
```

### Installation without Symfony Flex

__NOTE__: If you are using Symfony Flex (you probably do), you can skip this step.

Enable the bundle by adding it to the list of registered bundles in the file 
`config/bundles.php` in your project.

```php
// config/bundles.php

return [
    // ...
    NetBrothers\VersionBundle\NetBrothersVersionBundle::class => ['all' => true],
];
```

### Installation with Symfony Flex

By requiring the package with Composer, Symfony Flex automatically registers it
as a Symfony bundle. However, there's still some configuration necessary.

1. Copy the file [netbrothers_version.yaml](install/config/packages/netbrothers_version.yaml) from the `install` folder of this package to your Symfony project's config path.

2. If you use [Doctrine Migrations](https://symfony.com/doc/current/bundles/DoctrineMigrationsBundle/index.html)
    - instruct it to ignore your version tables, by customizing the
schema filter in the `doctrine.yaml` config file with `schema_filter: ~(?<!_version)$~`
for instance. __NOTE__: Otherwise Doctrine Migrations may drop your version tables
on the next occasion. You can find a configuration example in the file
[doctrine_example.yaml](install/config/packages/doctrine_example.yaml) in the
`install` folder of this package.
   - Open `netbrothers_version.yaml` and configure Doctrine's migration table
(it's mostly named `doctrine_migration_versions`) to be ignored. __NOTE__: If you
do not do this, this table will also be versioned.

3. You can specify columns by name to be always ignored by the compare algorithm 
when creating versions. This is also done in the file `netbrothers_version.yaml`.

4. Clear Symfony's cache: `bin/console cache:clear`.

## Usage

### Prepare your Entities/Origin Tables

Add a column named `version` (type `INT`/`BIGINT`) to every table you want to
be versioned. This can be done by adding the Trait
[VersionColumn](src/Traits/VersionColumn.php) to your entities and then creating
and applying a migration (e.g. with `bin/console make:migration`).

### Create Version Tables and Triggers

Issue the following command.

```console
bin/console netbrothers:version 
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
bin/console netbrothers:version [<tableName>]
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
