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
    /** @var string[] */
    private array $errors = [];
    private ?string $errMessage = null;

    /** @var string[] */
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
     * Check if column definitions are the same in both tables.
     */
    public function compare(Table $tableOne, Table $tableTwo): bool
    {
        $this->errors = [];
        $this->errMessage = null;

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
            $name = $column->getObjectName()->getIdentifier()->getValue();
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
            $this->errors[] = $columnName . ' does not exist in table ' . $table->getObjectName()->getUnqualifiedName()->getValue();
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

        $columnType = $column->getTypeName();
        $compareColumnType = $compareColumn->getTypeName();
        if ($columnType !== $compareColumnType) {
            $this->errors[] = sprintf('different types (%s != %s)', $columnType, $compareColumnType);
            $valid = false;
        }

        if ($column->getLength() !== $compareColumn->getLength()) {
            $this->errors[] = sprintf(
                'different lengths (%s != %s)',
                $this->formatValue($column->getLength()),
                $this->formatValue($compareColumn->getLength()),
            );
            $valid = false;
        }

        if ($column->getPrecision() !== $compareColumn->getPrecision()) {
            $this->errors[] = sprintf(
                'different precisions (%s != %s)',
                $this->formatValue($column->getPrecision()),
                $this->formatValue($compareColumn->getPrecision()),
            );
            $valid = false;
        }

        if ($column->getScale() !== $compareColumn->getScale()) {
            $this->errors[] = sprintf(
                'different scales (%d != %d)',
                $column->getScale(),
                $compareColumn->getScale(),
            );
            $valid = false;
        }

        if ($column->getUnsigned() !== $compareColumn->getUnsigned()) {
            $this->errors[] = 'different unsigned definitions';
            $valid = false;
        }

        if ($column->getFixed() !== $compareColumn->getFixed()) {
            $this->errors[] = 'different fixed definitions';
            $valid = false;
        }

        $defaultValue = $column->getDefault();
        $compareValue = $compareColumn->getDefault();
        if ($defaultValue !== $compareValue) {
            $this->errors[] = sprintf(
                'different default values (%s != %s)',
                $this->formatValue($defaultValue),
                $this->formatValue($compareValue),
            );
            $valid = false;
        }

        if ($column->getNotnull() !== $compareColumn->getNotnull()) {
            $this->errors[] = 'different NULL definitions';
            $valid = false;
        }

        if ($column->getCharset() !== $compareColumn->getCharset()) {
            $this->errors[] = sprintf(
                'different charsets (%s != %s)',
                $this->formatValue($column->getCharset()),
                $this->formatValue($compareColumn->getCharset()),
            );
            $valid = false;
        }

        if ($column->getCollation() !== $compareColumn->getCollation()) {
            $this->errors[] = sprintf(
                'different collations (%s != %s)',
                $this->formatValue($column->getCollation()),
                $this->formatValue($compareColumn->getCollation()),
            );
            $valid = false;
        }

        if ($column->getValues() !== $compareColumn->getValues()) {
            $this->errors[] = 'different enum values';
            $valid = false;
        }

        return $valid;
    }

    private function formatValue(mixed $value): string
    {
        if (null === $value) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return var_export($value, true);
    }
}
