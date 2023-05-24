<?php

/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 */

namespace NetBrothers\VersionBundle\Services;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use NetBrothers\VersionBundle\Services\Sql\CreateTrigger;
use NetBrothers\VersionBundle\Services\Sql\Definitions;
use NetBrothers\VersionBundle\Services\Sql\DropTrigger;
use NetBrothers\VersionBundle\Services\Sql\VersionTable;

/**
 * Class GenerateService
 * @package NetBrothers\VersionBundle\Services
 */
class GenerateService
{
    /** @var AbstractSchemaManager */
    private $schemaManager;

    /** @var string */
    private $databaseName;

    /**
     * GenerateService constructor.
     * @param AbstractSchemaManager $schemaManager
     * @param string $databaseName
     */
    public function __construct(
        AbstractSchemaManager $schemaManager,
        string $databaseName
    ) {
        $this->schemaManager = $schemaManager;
        $this->databaseName = $databaseName;
    }

    /** dropping triggers in origin table / dropping version table
     *
     * @param string $tableName name of origin table
     * @return array
     */
    public function dropVersionTableAndTriggersInOriginTable(string $tableName): array
    {
        $sql = [];
        // $versionTable Name of version table
        $versionTable = $tableName . Definitions::VERSION_TABLE_NAME_POSTFIX;
        if ($this->schemaManager->tablesExist([$versionTable])) {
            // version table exists => dropping triggers in origin table
            $sql = $this->dropTriggers($tableName);
            // dropping version
            $versionService = new VersionTable();
            $sql[] = $versionService->dropVersionTable(
                $this->databaseName,
                $tableName
            );
        }
        return $sql;
    }

    /** dropping triggers in origin table
     *
     * @param string $tableName name of origin table
     * @return array
     */
    public function dropTriggers(string $tableName): array
    {
        if ($this->schemaManager->tablesExist([$tableName])) {
            $dropTriggerService = new DropTrigger();
            return $dropTriggerService->getSql($this->databaseName, $tableName);
        }
        return [];
    }

    /**
     * @param string $tableName name of origin table
     * @return array
     */
    public function createVersionAndTriggers(string $tableName): array
    {
        $sql = [];
        if ($this->schemaManager->tablesExist([$tableName])) {
            // $versionTable Name of version table
            $versionTable = $tableName . Definitions::VERSION_TABLE_NAME_POSTFIX;
            if (true !== $this->schemaManager->tablesExist([$versionTable])) {
                $versionService = new VersionTable();
                $sql = $versionService->getSqlCreate($this->databaseName, $tableName);
                $triggerService = new CreateTrigger($this->schemaManager);
                foreach ($triggerService->getSql($this->databaseName, $tableName) as $query) {
                    $sql[] = $query;
                }
            }
        }
        return $sql;
    }

    /**
     * @param string $tableName
     * @return array
     */
    public function createTriggers(string $tableName): array
    {
        $sql = [];
        if ($this->schemaManager->tablesExist([$tableName])) {
            $triggerService = new CreateTrigger($this->schemaManager);
            $sql = $triggerService->getSql($this->databaseName, $tableName);
        }
        return $sql;
    }
}
