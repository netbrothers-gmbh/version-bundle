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
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * generating sql for creating triggers
 */
final class CreateTrigger
{
    private AbstractSchemaManager $schemaManager;
    private AbstractPlatform $platform;

    /** @var string CREATE DEFINER = CURRENT_USER TRIGGER db.table_%s */
    private string $fCreateSql;

    /** @var string INSERT INTO (...) VALUES (...) */
    private string $triggerBody;

    private string $fTriggerSql = <<<EOF
%s %s ON `%s` 
FOR EACH ROW
BEGIN
    %s 
END

EOF;

    /**
     * @throws Exception
     */
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        $connection = $this->entityManager->getConnection();
        $this->schemaManager = $connection->createSchemaManager();
        $this->platform = $connection->getDatabasePlatform();
    }

    /**
     * creating sql for insert triggers in one table
     * @return string[]
     * @throws Exception
     */
    public function getSql(string $databaseName, string $tableName): array
    {
        $versionTableName = $tableName . Definitions::VERSION_TABLE_NAME_POSTFIX;
        $this->setTriggerBody($databaseName, $tableName, $versionTableName);
        $this->setFCreateSql($databaseName, $tableName);
        $sql = [];
        $sql[] = $this->createTriggerBeforeInsert($tableName);
        $sql[] = $this->createTriggerAfterInsert($tableName);
        $sql[] = $this->createTriggerBeforeUpdate($tableName);
        $sql[] = $this->createTriggerAfterUpdate($tableName);
        return $sql;
    }

    private function setFCreateSql(string $databaseName, string $tableName): void
    {
        $inner = sprintf("`%s`.`%s", $databaseName, $tableName);
        $this->fCreateSql = "CREATE DEFINER = CURRENT_USER TRIGGER " . $inner . "_%s`";
    }

    /**
     * creating trigger "setting version to int 1 before insert"
     */
    private function createTriggerBeforeInsert(string $tableName): string
    {
        $triggerName = Definitions::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION;
        $createStatement = sprintf($this->fCreateSql, $triggerName);
        $eventStatement = "BEFORE INSERT";
        $triggerBody = "SET NEW.version = 1;";
        return sprintf($this->fTriggerSql, $createStatement, $eventStatement, $tableName, $triggerBody);
    }

    /**
     * creating trigger "create first entry in table version"
     */
    private function createTriggerAfterInsert(string $tableName): string
    {
        $triggerName = Definitions::TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION;
        $createStatement = sprintf($this->fCreateSql, $triggerName);
        $eventStatement = "AFTER INSERT";
        return sprintf($this->fTriggerSql, $createStatement, $eventStatement, $tableName, $this->triggerBody);
    }

    /**
     * creating trigger "increment column version before update"
     */
    private function createTriggerBeforeUpdate(string $tableName): string
    {
        $triggerName = Definitions::TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION;
        $createStatement = sprintf($this->fCreateSql, $triggerName);
        $eventStatement = "BEFORE UPDATE";
        $triggerBody = "SET NEW.version = OLD.version+1;";
        return sprintf($this->fTriggerSql, $createStatement, $eventStatement, $tableName, $triggerBody);
    }

    /**
     * insert version to version table by update
     */
    private function createTriggerAfterUpdate(string $tableName): string
    {
        $triggerName = Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION;
        $createStatement = sprintf($this->fCreateSql, $triggerName);
        $eventStatement = "AFTER UPDATE";
        return sprintf($this->fTriggerSql, $createStatement, $eventStatement, $tableName, $this->triggerBody);
    }

    /**
     * @throws Exception
     */
    private function setTriggerBody(string $databaseName, string $tableName, string $versionTableName): void
    {
        $tableColumns = $this->schemaManager->introspectTableByUnquotedName($tableName)->getColumns();
        $columnNames = [];
        $values = [];
        foreach ($tableColumns as $column) {
            $quotedColName = $column->getObjectName()->toSQL($this->platform);
            $columnNames[] = $quotedColName;
            $values[] = "NEW." . $quotedColName;
        }
        $stringColumnNames = "(" . implode(",", $columnNames) . ")";
        $stringValues = "(" . implode(",", $values) . ")";
        $this->triggerBody = "INSERT INTO `$databaseName`.`$versionTableName` $stringColumnNames VALUES $stringValues;";
    }
}
