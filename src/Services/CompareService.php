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

namespace NetBrothers\VersionBundle\Services;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;

/**
 * Provides check, if column definitions are identical in two tables.
 */
final class CompareService
{
    private array $errors = [];
    private ?string $errMessage = null;
    private readonly array $excludeColumnNames;

    public function __construct(VersionConfigService $configService)
    {
        $this->excludeColumnNames = $configService->getIgnoreColumns();
    }

    public function getErrMessage(): ?string
    {
        return $this->errMessage;
    }

    /**
     * check if column definitions are the same in both tables
     */
    public function compare(Table $tableOne, Table $tableTwo): bool
    {
        $columnsInTableOne = $tableOne->getColumns();
        if (!$this->compareColumns($columnsInTableOne, $tableTwo)) {
            return false;
        }
        $columnsInTableTwo = $tableTwo->getColumns();
        if (!$this->compareColumns($columnsInTableTwo, $tableOne)) {
            return false;
        }
        return true;
    }

    /**
     * @param Column[] $columnsInTable
     */
    private function compareColumns(array $columnsInTable, Table $compareTable): bool
    {
        foreach ($columnsInTable as $column) {
            $name = $column->getObjectName()->toString();
            if (in_array($name, $this->excludeColumnNames, true)) {
                continue;
            }
            $compareColumn = $this->getColumn($compareTable, $name);
            if (null === $compareColumn) {
                $msg = sprintf("Error comparing %s", $name) . PHP_EOL;
                $this->errMessage = $msg . implode(PHP_EOL . " => ", $this->errors);
                return false;
            }
            if (!$this->compareOneColumn($column, $compareColumn)) {
                $msg = sprintf("Error comparing %s", $name) . PHP_EOL;
                $this->errMessage = $msg . implode(PHP_EOL . " => ", $this->errors);
                return false;
            }
        }
        return true;
    }

    private function getColumn(Table $table, string $columnName): ?Column
    {
        if (!$table->hasColumn($columnName)) {
            $this->errors[] = $columnName . ' does not exist in table ' . $table->getName();
            return null;
        }
        try {
            return $table->getColumn($columnName);
        } catch (SchemaException $e) {
            $this->errors[] = $columnName . ': ' . $e->getMessage();
            return null;
        }
    }

    private function compareOneColumn(Column $column, Column $compareColumn): bool
    {
        $valid = true;

        // compare types
        $columnType = $column->getType();
        $compareColumnType = $compareColumn->getType();
        if (!$columnType instanceof $compareColumnType) {
            $this->errors[] = 'different types';
            $valid = false;
        }

        // compare default values
        $defaultValue = $column->getDefault();
        $compareValue = $compareColumn->getDefault();
        if ($defaultValue !== $compareValue) {
            $this->errors[] = sprintf(
                'different default values (%s != %s)',
                $defaultValue,
                $compareValue
            );
            $valid = false;
        }

        // compare NULL definition
        if ($column->getNotnull() !== $compareColumn->getNotnull()) {
            $this->errors[] = "different NULL definitions";
            $valid = false;
        }
        return $valid;
    }
}
