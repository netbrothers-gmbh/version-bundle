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

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;

/**
 * Derives the expected Doctrine DBAL Table definition for a `_version` table
 * from its corresponding origin Table.
 */
final readonly class VersionTableSchemaBuilder
{
    private const array PRESERVED_TABLE_OPTIONS = ['engine', 'charset', 'collation', 'comment'];

    public function __construct(
        private IdentifierHelper $identifierHelper,
    ) {
    }

    public function buildExpectedVersionTable(Table $originTable): Table
    {
        $originTableName = $originTable->getObjectName()->getUnqualifiedName()->getValue();
        $versionTableName = $this->identifierHelper->buildVersionTableName($originTableName);

        $originPkColumns = $this->extractOriginPrimaryKeyColumnNames($originTable);
        $versionPkColumns = $this->buildVersionPrimaryKeyColumnNames($originPkColumns);

        $tableEditor = Table::editor();
        $tableEditor->setUnquotedName($versionTableName);

        foreach ($originTable->getColumns() as $column) {
            $columnName = $column->getObjectName()->getIdentifier()->getValue();
            $isPkColumn = in_array($columnName, $versionPkColumns, true);

            $columnEditor = $column->edit();
            $columnEditor->setAutoincrement(false);
            $columnEditor->setNotnull($isPkColumn);

            $tableEditor->addColumn($columnEditor->create());
        }

        if ($versionPkColumns !== []) {
            $firstPkColumn = $versionPkColumns[0];
            $otherPkColumns = array_slice($versionPkColumns, 1);

            $tableEditor->setPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()
                    ->setUnquotedColumnNames($firstPkColumn, ...$otherPkColumns)
                    ->create(),
            );
        }

        if ($originPkColumns !== []) {
            $firstOriginPk = $originPkColumns[0];
            $otherOriginPk = array_slice($originPkColumns, 1);

            $fkName = $this->identifierHelper->buildForeignKeyName($originTableName);
            $tableEditor->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName($fkName)
                    ->setUnquotedReferencingColumnNames($firstOriginPk, ...$otherOriginPk)
                    ->setUnquotedReferencedTableName($originTableName)
                    ->setUnquotedReferencedColumnNames($firstOriginPk, ...$otherOriginPk)
                    ->setOnDeleteAction(ReferentialAction::CASCADE)
                    ->create(),
            );
        }

        $options = $this->extractPreservedTableOptions($originTable);
        if ($options !== []) {
            $tableEditor->setOptions($options);
        }

        return $tableEditor->create();
    }

    /**
     * @return non-empty-string[]
     */
    private function extractOriginPrimaryKeyColumnNames(Table $originTable): array
    {
        $pkConstraint = $originTable->getPrimaryKeyConstraint();
        if ($pkConstraint !== null) {
            $names = [];
            foreach ($pkConstraint->getColumnNames() as $columnName) {
                $names[] = $columnName->getIdentifier()->getValue();
            }

            return $names;
        }

        if ($originTable->hasColumn('id')) {
            return ['id'];
        }

        return [];
    }

    /**
     * @param non-empty-string[] $originPkColumns
     * @return non-empty-string[]
     */
    private function buildVersionPrimaryKeyColumnNames(array $originPkColumns): array
    {
        $columns = $originPkColumns;
        if (!in_array('version', $columns, true)) {
            $columns[] = 'version';
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractPreservedTableOptions(Table $originTable): array
    {
        $preserved = [];
        foreach ($originTable->getOptions() as $key => $value) {
            if (in_array($key, self::PRESERVED_TABLE_OPTIONS, true)) {
                $preserved[$key] = $value;
            }
        }

        return $preserved;
    }
}
