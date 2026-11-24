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

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Table;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaBuilder;
use NetBrothers\VersionBundle\Services\VersionConfigService;

/**
 * Generates DBAL-based DDL for creating/dropping version tables and safely backfilling existing rows.
 */
final readonly class VersionTable
{
    public function __construct(
        private VersionTableSchemaBuilder $schemaBuilder,
        private VersionConfigService $configService,
    ) {
    }

    /**
     * Returns all SQL statements needed to create a version table via DBAL DDL
     * and safely backfill existing rows (normalizing NULL version values to 1).
     *
     * @return string[]
     * @throws Exception
     */
    public function getSqlCreate(Table $originTable, AbstractPlatform $platform): array
    {
        $ignoreColumns = $this->configService->getIgnoreColumns();
        $expectedVersionTable = $this->schemaBuilder->buildExpectedVersionTable($originTable, $ignoreColumns);
        $sql = $platform->getCreateTableSQL($expectedVersionTable);

        $originTableName = $originTable->getObjectName()->getUnqualifiedName()->getValue();
        $versionTableName = $expectedVersionTable->getObjectName()->getUnqualifiedName()->getValue();

        $quotedOrigin = $platform->quoteSingleIdentifier($originTableName);
        $quotedVersionTable = $platform->quoteSingleIdentifier($versionTableName);
        $quotedVersionColumn = $platform->quoteSingleIdentifier('version');

        $quotedColumns = [];
        foreach ($expectedVersionTable->getColumns() as $column) {
            $quotedColumns[] = $platform->quoteSingleIdentifier(
                $column->getObjectName()->getIdentifier()->getValue(),
            );
        }

        $sql[] = sprintf(
            'UPDATE %s SET %s = 1 WHERE %s IS NULL',
            $quotedOrigin,
            $quotedVersionColumn,
            $quotedVersionColumn,
        );

        $columnList = implode(', ', $quotedColumns);
        $sql[] = sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s',
            $quotedVersionTable,
            $columnList,
            $columnList,
            $quotedOrigin,
        );

        return $sql;
    }

    /**
     * Returns the SQL statement to drop a version table by its full table name.
     */
    public function dropVersionTable(string $versionTableName, AbstractPlatform $platform): string
    {
        return sprintf('DROP TABLE IF EXISTS %s', $platform->quoteSingleIdentifier($versionTableName));
    }
}
