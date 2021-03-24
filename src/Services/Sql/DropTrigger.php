<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 19.03.21
 *
 */

namespace NetBrothers\VersionBundle\Services\Sql;

/** generating sql for dropping triggers
 *
 * Class DropTrigger
 * @package NetBrothers\VersionBundle\Services\Sql
 */
class DropTrigger extends Definitions
{
    /** @var string */
    private $fDrop = '';

    /**
     * @param string $databaseName
     * @param string $tableName
     */
    private function setDropStatement(string $databaseName, string $tableName)
    {
        $inner = sprintf("`%s`.`%s", $databaseName, $tableName);
        $this->fDrop = "DROP TRIGGER IF EXISTS " . $inner . "_%s`;";
    }

    /**
     * dropping trigger "setting version to int 1 before insert"
     *
     * @return string
     */
    private function dropTriggerBeforeInsert(): string
    {
        $triggerName = parent::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION;
        return sprintf($this->fDrop, $triggerName);
    }

    /**
     * dropping trigger "create first entry in table version"
     *
     * @return string
     */
    private function dropTriggerAfterInsert(): string
    {
        $triggerName = parent::TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION;
        return sprintf($this->fDrop, $triggerName);
    }

    /**
     * dropping trigger "increment column version before update"
     *
     * @return string
     */
    private function dropTriggerBeforeUpdate(): string
    {
        $triggerName = parent::TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION;
        return sprintf($this->fDrop, $triggerName);
    }

    /**
     * dropping trigger "insert in table version on update"
     *
     * @return string
     */
    private function dropTriggerAfterUpdate(): string
    {
        $triggerName = parent::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION;
        return sprintf($this->fDrop, $triggerName);
    }

    /**
     * generates sql-Statement for dropping triggers
     * @param string $databaseName
     * @param string $tableName
     * @return array
     */
    public function getSql(string $databaseName, string $tableName): array
    {
        $this->setDropStatement($databaseName, $tableName);
        $sql = [];
        $sql[] = $this->dropTriggerBeforeInsert();
        $sql[] = $this->dropTriggerAfterInsert();
        $sql[] = $this->dropTriggerBeforeUpdate();
        $sql[] = $this->dropTriggerAfterUpdate();
        return $sql;
    }
}