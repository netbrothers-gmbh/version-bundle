NetBrothers Versions for Symfony
===================================
This is a symfony bundle for managing versions in version tables.

The bundle offers a command, which will create version tables based on the columns in your
origin tables: All tables with a column named `version` (type INT/BIGINT) will have a corresponding 
version table called `[originTableName]_version` with same columns of the originTable. 

Every originTable gets trigger, which will increase the version column on insert/updates and 
saves a copy in the version table.

__NOTE__: This works only for MySQL-Databases.

Installation
============
Make sure Composer is installed globally, as explained in the
[installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

Applications that use Symfony Flex
----------------------------------

Open a command console, enter your project directory and execute:

```console
composer require netbrothers-gmbh/version-bundle
```

Applications that don't use Symfony Flex
----------------------------------------

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```console
composer require netbrothers-gmbh/version-bundle
```

### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `config/bundles.php` file of your project:

```php
// config/bundles.php

return [
    // ...
    NetBrothers\VersionBundle\NetBrothersVersionBundle::class => ['all' => true],
];
```

Setup
=============
You have to set up the bundle:

1. Copy `installation/config/packages/netbrothers_version.yaml` to symfony's config path.


2. If you use Doctrine's migration:
   - Find out the config file `doctrine.yaml` and insert `schema_filter: ~(?<!_version)$~`
     (find an example under `installation/config/packages/doctrine_example.yaml`).
     This tells Doctrine migrations to ignore the version tables. If you do not do this, 
     doctrine migrations will drop the version tables on next migration!
   - Open `netbrothers_version.yaml` and set Doctrine's migration table as ignored. If you do not do this,
     this table will also have a version table!


3. In `netbrothers_version.yaml` you can define columns, which are always ignored by the compare algorithm
   (The command always compares existing version tables with their origin tables). 
   

4. Clear symfony's cache.

Usage
=====

1. Prepare your origin tables:
    Add a column named `version` (type INT/BIGINT) to every table, you wish to have a version table.
    Best practice is to use the trait `src/Traits/VersionColumn.php` in your entities and make a migration.
2. Open a command console, enter your project directory and execute the following command:
```console
php bin/console netbrothers:version 
```

The command will now recognize all tables with a `version`-column:
* Create a version table, if no one exists.
* If a version table exists, compare the columns in both tables.
* Drop existing triggers.
* Create triggers.

You can specify a single table as argument. The command will just check this table:
```console
php bin/console netbrothers:version blog
```

You can specify some options:

| option                    | meaning |
| -----------               | ------- |
| create-trigger (default)  | Drop triggers, create not existing version tables, create triggers |
| drop-trigger              | Drop triggers                                                   |
| drop-version              | Drop triggers, drop version table                               |
| summary                   | print todos to stdout  - do not execute                         |
| sql                       | print prepared SQL-Statements to stdout - do not execute        |
| dry-run                   | Test SQL-Statements (execute and roll back)                     |


Author
======
[Stefan Wessel, NetBrothers GmbH](https://netbrothers.de)

[![nb.logo](https://netbrothers.de/wp-content/uploads/2020/12/netbrothers_logo.png)](https://netbrothers.de)

Licence
=======
MIT