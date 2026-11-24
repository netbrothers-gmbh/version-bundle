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

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Table;

/**
 * Generates SQL statements for creating versioning triggers on MariaDB/MySQL tables.
 */
final readonly class CreateTrigger
{
    public function __construct(
        private IdentifierHelper $identifierHelper,
    ) {
    }

    /**
     * Generates CREATE TRIGGER statements for all four versioning triggers of an origin table.
     *
     * @return string[]
     */
    public function getSql(string $databaseName, Table $originTable, AbstractPlatform $platform): array
    {
        $tableName = $originTable->getObjectName()->getUnqualifiedName()->getValue();
        $versionTableName = $this->identifierHelper->buildVersionTableName($tableName);

        $quotedDb = $platform->quoteSingleIdentifier($databaseName);
        $quotedTable = $platform->quoteSingleIdentifier($tableName);
        $quotedVersionTable = $platform->quoteSingleIdentifier($versionTableName);
        $quotedVersionCol = $platform->quoteSingleIdentifier('version');

        $insertBody = $this->buildInsertBody($originTable, $quotedDb, $quotedVersionTable, $platform);

        return [
            $this->buildTriggerSql(
                $quotedDb,
                $tableName,
                $quotedTable,
                Definitions::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION,
                'BEFORE INSERT',
                sprintf('SET NEW.%s = 1;', $quotedVersionCol),
                $platform,
            ),
            $this->buildTriggerSql(
                $quotedDb,
                $tableName,
                $quotedTable,
                Definitions::TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION,
                'AFTER INSERT',
                $insertBody,
                $platform,
            ),
            $this->buildTriggerSql(
                $quotedDb,
                $tableName,
                $quotedTable,
                Definitions::TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION,
                'BEFORE UPDATE',
                sprintf('SET NEW.%s = COALESCE(OLD.%s, 0) + 1;', $quotedVersionCol, $quotedVersionCol),
                $platform,
            ),
            $this->buildTriggerSql(
                $quotedDb,
                $tableName,
                $quotedTable,
                Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION,
                'AFTER UPDATE',
                $insertBody,
                $platform,
            ),
        ];
    }

    private function buildInsertBody(
        Table $originTable,
        string $quotedDb,
        string $quotedVersionTable,
        AbstractPlatform $platform,
    ): string {
        $columnNames = [];
        $values = [];
        foreach ($originTable->getColumns() as $column) {
            $quotedCol = $platform->quoteSingleIdentifier(
                $column->getObjectName()->getIdentifier()->getValue(),
            );
            $columnNames[] = $quotedCol;
            $values[] = 'NEW.' . $quotedCol;
        }

        return sprintf(
            'INSERT INTO %s.%s (%s) VALUES (%s);',
            $quotedDb,
            $quotedVersionTable,
            implode(', ', $columnNames),
            implode(', ', $values),
        );
    }

    private function buildTriggerSql(
        string $quotedDb,
        string $tableName,
        string $quotedTable,
        string $triggerSuffix,
        string $event,
        string $body,
        AbstractPlatform $platform,
    ): string {
        $triggerName = $this->identifierHelper->buildTriggerName($tableName, $triggerSuffix);
        $quotedTrigger = $platform->quoteSingleIdentifier($triggerName);

        return sprintf(
            "CREATE DEFINER = CURRENT_USER TRIGGER %s.%s %s ON %s\nFOR EACH ROW\nBEGIN\n    %s\nEND",
            $quotedDb,
            $quotedTrigger,
            $event,
            $quotedTable,
            $body,
        );
    }
}
