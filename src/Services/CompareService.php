<?php

/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 */

namespace NetBrothers\VersionBundle\Services;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;

/**
 * Provides check, if column definitions are identical in two tables.
 * 
 * @package NetBrothers\VersionBundle\Services
 */
class CompareService
{
    /** @var string[]  */
    private array $errors = [];

    private ?string $errMessage = null;

    /** @var string[]  */
    private array $excludeColumnNames = ['id', 'version', 'created_at', 'updated_at'];

    /**
     * CompareService constructor.
     * @param array|null $excludeColumnNames columns should not be compared
     */
    public function __construct(?array $excludeColumnNames = null)
    {
        if (is_array($excludeColumnNames) && 0 < count($excludeColumnNames)) {
            $this->excludeColumnNames = $excludeColumnNames;
        }
    }

    /**
     * @return string|null
     */
    public function getErrMessage(): ?string
    {
        return $this->errMessage;
    }

    /** check if column definitions are the same in both tables
     *
     * @param Table $tableOne
     * @param Table $tableTwo
     * @return bool
     */
    public function compare(Table $tableOne, Table $tableTwo): bool
    {
        $columnsInTableOne = $tableOne->getColumns();
        if (true !== $this->compareColumns($columnsInTableOne, $tableTwo)) {
            return false;
        }
        $columnsInTableTwo = $tableTwo->getColumns();
        if (true !== $this->compareColumns($columnsInTableTwo, $tableOne)) {
            return false;
        }
        return true;
    }

    /**
     * @param Column[] $columnsInTable
     * @param Table $compareTable
     * @return bool
     */
    private function compareColumns($columnsInTable, Table $compareTable): bool
    {
        foreach ($columnsInTable as $column) {
            $name = $column->getName();
            if (in_array($name, $this->excludeColumnNames)) {
                continue;
            }
            $compareColumn = $this->getColumn($compareTable, $name);
            if (null === $compareColumn) {
                $msg = sprintf("Error comparing %s", $name) . PHP_EOL;
                $this->errMessage = $msg . implode(PHP_EOL . " => ", $this->errors);
                return false;
            }
            if( true !== $this->compareOneColumn($column, $compareColumn)) {
                $msg = sprintf("Error comparing %s", $name) . PHP_EOL;
                $this->errMessage = $msg . implode(PHP_EOL . " => ", $this->errors);
                return false;
            }
        }
        return true;
    }

    /**
     * @param Table $table
     * @param string $columnName
     * @return Column|null
     */
    private function getColumn(Table $table, string $columnName): ?Column
    {
        if (true !== $table->hasColumn($columnName)) {
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

    /**
     * @param Column $column
     * @param Column $compareColumn
     * @return bool
     */
    private function compareOneColumn(Column $column, Column $compareColumn): bool
    {
        $valid = true;

        // compare types
        $columnType = $column->getType();
        $compareColumnType = $compareColumn->getType();
        if (! $columnType instanceof $compareColumnType) {
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
