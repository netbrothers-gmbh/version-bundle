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

namespace NetBrothers\VersionBundle\Services\Schema;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use NetBrothers\VersionBundle\Services\VersionConfigService;

/**
 * Computes column-level schema differences between an existing `_version` table and the expected
 * version table derived from its origin table, generating Doctrine DBAL ALTER TABLE statements.
 */
final readonly class VersionTableSchemaSyncer
{
    public function __construct(
        private VersionTableSchemaBuilder $schemaBuilder,
        private VersionConfigService $configService,
    ) {
    }

    /**
     * @throws Exception
     */
    public function planSync(
        Table $originTable,
        Table $actualVersionTable,
        Comparator $comparator,
        AbstractPlatform $platform,
    ): TableSyncPlan {
        $originTableName = $originTable->getObjectName()->getUnqualifiedName()->getValue();
        $versionTableName = $actualVersionTable->getObjectName()->getUnqualifiedName()->getValue();

        $protectedPkColumns = $this->schemaBuilder->getVersionPrimaryKeyColumnNames($originTable);
        $ignoredColumns = array_values(array_diff($this->configService->getIgnoreColumns(), $protectedPkColumns));

        $expectedVersionTable = $this->schemaBuilder->buildExpectedVersionTable($originTable, $ignoredColumns);

        $actualForDiff = $this->buildColumnOnlyTable(
            $actualVersionTable,
            $actualVersionTable->getColumns(),
            $ignoredColumns,
            $actualVersionTable->getOptions(),
        );

        $expectedColumns = $expectedVersionTable->getColumns();
        foreach ($protectedPkColumns as $pkColumnName) {
            if (!$expectedVersionTable->hasColumn($pkColumnName) && $actualVersionTable->hasColumn($pkColumnName)) {
                $expectedColumns[] = $actualVersionTable->getColumn($pkColumnName);
            }
        }

        $expectedOptions = $expectedVersionTable->getOptions();
        if ($expectedOptions === []) {
            $expectedOptions = $actualVersionTable->getOptions();
        }

        $expectedForDiff = $this->buildColumnOnlyTable(
            $actualVersionTable,
            $expectedColumns,
            $ignoredColumns,
            $expectedOptions,
        );

        $tableDiff = $comparator->compareTables($actualForDiff, $expectedForDiff);

        $addedColumns = [];
        foreach ($tableDiff->getAddedColumns() as $column) {
            $addedColumns[] = $column->getObjectName()->getIdentifier()->getValue();
        }

        $modifiedColumns = [];
        foreach ($tableDiff->getChangedColumns() as $columnDiff) {
            $modifiedColumns[] = $columnDiff->getNewColumn()->getObjectName()->getIdentifier()->getValue();
        }

        $droppedColumns = [];
        foreach ($tableDiff->getDroppedColumns() as $column) {
            $droppedColumns[] = $column->getObjectName()->getIdentifier()->getValue();
        }

        $sql = $tableDiff->isEmpty() ? [] : $platform->getAlterTableSQL($tableDiff);

        return new TableSyncPlan(
            originTableName: $originTableName,
            versionTableName: $versionTableName,
            addedColumns: $addedColumns,
            modifiedColumns: $modifiedColumns,
            droppedColumns: $droppedColumns,
            sql: $sql,
        );
    }

    /**
     * Builds a column-only projection of a Table (without indexes or foreign keys)
     * so Comparator::compareTables() produces pure column ADD/MODIFY/DROP diffs.
     *
     * @param \Doctrine\DBAL\Schema\Column[] $columns
     * @param string[] $ignoredColumns
     * @param array<string, mixed> $options
     */
    private function buildColumnOnlyTable(
        Table $targetTable,
        array $columns,
        array $ignoredColumns,
        array $options,
    ): Table {
        $editor = Table::editor();
        $editor->setName($targetTable->getObjectName());

        foreach ($columns as $column) {
            $columnName = $column->getObjectName()->getIdentifier()->getValue();
            if (in_array($columnName, $ignoredColumns, true)) {
                continue;
            }
            $editor->addColumn($column);
        }

        if ($options !== []) {
            $editor->setOptions($options);
        }

        return $editor->create();
    }
}
