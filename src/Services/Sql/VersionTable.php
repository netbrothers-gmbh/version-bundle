<?php

declare(strict_types=1);

/*
 * This file is part of the NetBrothers VersionBundle.
 *
 * (c) 2021 NetBrothers GmbH | Thilo Ratnaweera & Stefan Wessel (https://netbrothers.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NetBrothers\VersionBundle\Services\Sql;

/**
 * Generates SQL for dropping/creating version tables.
 */
final class VersionTable
{
    /**
     * Returns all SQL statements needed to create a version table with its constraints.
     *
     * @return string[]
     */
    public function getSqlCreate(string $databaseName, string $tableName): array
    {
        $versionTableName = $tableName . Definitions::VERSION_TABLE_NAME_POSTFIX;

        return [
            sprintf(Definitions::SQL_CREATE_TABLE, $databaseName, $versionTableName, $databaseName, $tableName),
            sprintf(Definitions::SQL_ADD_PK_TO_VERSION, $databaseName, $versionTableName),
            sprintf(Definitions::SQL_ADD_CONSTRAINT, $databaseName, $versionTableName, $versionTableName, $tableName),
        ];
    }

    /**
     * Returns the SQL statement to drop a version table.
     */
    public function dropVersionTable(string $databaseName, string $tableName): string
    {
        $versionTableName = $tableName . Definitions::VERSION_TABLE_NAME_POSTFIX;
        return sprintf(Definitions::SQL_DROP_TABLE, $databaseName, $versionTableName);
    }
}
