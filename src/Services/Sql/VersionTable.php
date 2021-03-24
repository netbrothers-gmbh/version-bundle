<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 19.03.21
 *
 */

namespace NetBrothers\VersionBundle\Services\Sql;

/** drop / create version table
 *
 * Class VersionTable
 * @package NetBrothers\VersionBundle\Services\Sql
 */
class VersionTable extends Definitions
{

    /** @var string name of version table */
    private $version = '';

    /** @var string name of origin table */
    private $table = '';

    /** @var string name of database */
    private $database = '';

    /** drop version table and create new
     *
     * @param string $databaseName
     * @param string $tableName
     * @return array
     */
    public function getSqlCreate(string $databaseName, string $tableName): array
    {
        $this->version = $tableName . parent::VERSION_TABLE_NAME_POSTFIX;
        $this->table = $tableName;
        $this->database = $databaseName;
        $sql = [];
        $sql[] = $this->sqlCreate();
        $sql[] = $this->sqlPk();
        $sql[] = $this->sqlConstraint();
        return $sql;

    }

    /** drop version table
     *
     * @param string $databaseName
     * @param string $tableName
     * @return string
     */
    public function dropVersionTable(string $databaseName, string $tableName): string
    {
        $this->version = $tableName . parent::VERSION_TABLE_NAME_POSTFIX;
        $this->database = $databaseName;
        return sprintf(parent::SQL_DROP_TABLE, $this->database, $this->version);
    }

    /**
     * @return string
     */
    private function sqlCreate(): string
    {
        return sprintf(parent::SQL_CREATE_TABLE, $this->database, $this->version, $this->database, $this->table);
    }

    /**
     * @return string
     */
    private function sqlPk(): string
    {
        return sprintf(parent::SQL_ADD_PK_TO_VERSION, $this->database, $this->version);
    }

    /**
     * @return string
     */
    private function sqlConstraint(): string
    {
        return sprintf(parent::SQL_ADD_CONSTRAINT, $this->database, $this->version, $this->version, $this->table);
    }


}