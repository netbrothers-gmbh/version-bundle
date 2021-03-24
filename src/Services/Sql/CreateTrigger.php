<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 19.03.21
 *
 */

namespace NetBrothers\VersionBundle\Services\Sql;


use Doctrine\DBAL\Schema\AbstractSchemaManager;

/** generating sql for creating triggers
 *
 * Class CreateTrigger
 * @package NetBrothers\VersionBundle\Services\Sql
 */
class CreateTrigger extends Definitions
{


    /** @var AbstractSchemaManager */
    private $schemaManager;

    /** @var string CREATE DEFINER = CURRENT_USER TRIGGER db.table_%s */
    private $fCreateSql;

    /** @var string INSERT INTO (...) VALUES (...) */
    private $triggerBody;

    private $fTriggerSql=<<<EOF
%s %s ON `%s` 
FOR EACH ROW
BEGIN
    %s 
END

EOF;


    /**
     * CreateTriggers constructor.
     * @param AbstractSchemaManager $schemaManager
     */
    public function __construct(AbstractSchemaManager $schemaManager)
    {
        $this->schemaManager = $schemaManager;
    }

    /**
     * creating sql for insert triggers in one table
     *
     * @param string $databaseName
     * @param string $tableName
     * @return array
     */
    public function getSql(string $databaseName, string $tableName): array
    {
        $versionTableName = $tableName . parent::VERSION_TABLE_NAME_POSTFIX;
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
     *
     * @param string $tableName
     * @return string
     */
    private function createTriggerBeforeInsert(string $tableName): string
    {
        $triggerName = parent::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION;
        $createStatement = sprintf($this->fCreateSql, $triggerName);
        $eventStatement = "BEFORE INSERT";
        $triggerBody = "SET NEW.version = 1;";
        return sprintf($this->fTriggerSql, $createStatement, $eventStatement, $tableName, $triggerBody);
    }

    /**
     * creating trigger "create first entry in table version"
     *
     * @param string $tableName
     * @return string
     */
    private function createTriggerAfterInsert(string $tableName): string
    {
        $triggerName = parent::TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION;
        $createStatement = sprintf($this->fCreateSql, $triggerName);
        $eventStatement = "AFTER INSERT";
        return sprintf($this->fTriggerSql, $createStatement, $eventStatement, $tableName, $this->triggerBody);
    }

    /**
     * creating trigger "increment column version before update"
     *
     * @param string $tableName
     * @return string
     */
    private function createTriggerBeforeUpdate(string $tableName): string
    {
        $triggerName = parent::TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION;
        $createStatement = sprintf($this->fCreateSql, $triggerName);
        $eventStatement = "BEFORE UPDATE";
        $triggerBody = "SET NEW.version = OLD.version+1;";
        return sprintf($this->fTriggerSql, $createStatement, $eventStatement, $tableName, $triggerBody);
    }

    /**
     * insert version to version table by update
     *
     * @param string $tableName
     * @return string
     */
    private function createTriggerAfterUpdate(string $tableName): string
    {
        $triggerName = parent::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION;
        $createStatement = sprintf($this->fCreateSql, $triggerName);
        $eventStatement = "AFTER UPDATE";
        return sprintf($this->fTriggerSql, $createStatement, $eventStatement, $tableName, $this->triggerBody);
    }

    /**
     * @param string $databaseName
     * @param string $tableName
     * @param string $versionTableName
     */
    private function setTriggerBody(string $databaseName, string $tableName, string $versionTableName): void
    {
        $tableColumns = $this->schemaManager->listTableColumns($tableName, $databaseName);
        $columnNames = [];
        $values = [];
        foreach ($tableColumns as $column) {
            $colName = $column->getName();
            $columnNames[] = "`$colName`";
            $values[] = "NEW." . $colName;
        }
        $stringColumnNames = "(" . implode(",", $columnNames) . ")";
        $stringValues = "(" . implode(",", $values) . ")";
        $this->triggerBody = "INSERT INTO `$databaseName`.`$versionTableName` $stringColumnNames VALUES $stringValues;";
    }
}