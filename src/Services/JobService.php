<?php

/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 19.03.21
 *
 */

namespace NetBrothers\VersionBundle\Services;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use NetBrothers\VersionBundle\Services\Sql\Definitions;

/**
 * Class JobService
 * @package NetBrothers\VersionBundle\Services
 */
class JobService
{
    /** @var array tables which should not be recognized */
    private $ignoreTables;

    /** @var array  */
    private $jobs = [];

    /** @var array names of table which were analysed */
    private $tableNames = [];

    /** @var bool  */
    private $warning = false;

    /** @var bool  */
    private $error   = false;

    /** @var AbstractSchemaManager */
    private $schemaManager;

    /** @var Definitions */
    private $definitionService;

    /** @var CompareService  */
    private $compareService;

    /** @var Schema */
    private $schema;

    /** @return bool */
    public function hasError(): bool
    {
        return $this->error;
    }

    /** @return bool */
    public function hasWarning(): bool
    {
        return $this->warning;
    }

    /** @return array */
    public function getReport(): array
    {
        return $this->jobs['Reports'];
    }

    /** @return array */
    public function getTableNames(): array
    {
        return $this->tableNames;
    }

    /** @param string $tableName */
    public function addTableName(string $tableName): void
    {
        $this->tableNames[] = $tableName;
    }

    /** initialize array jobs */
    private function initJobs(): void
    {
        $this->jobs = [
            'DropTrigger' => [],
            'CreateTrigger' => [],
            'DropVersion' => [],
            'CreateVersion' => [],
            'Reports'   => [],
        ];
    }

    /**
     * JobService constructor.
     * @param AbstractSchemaManager $schemaManager
     * @param array $ignoreTables
     */
    public function __construct(
        AbstractSchemaManager $schemaManager,
        CompareService $compareService,
        array $ignoreTables = []
    ) {
        $this->schemaManager = $schemaManager;
        $this->schema = $schemaManager->introspectSchema();
        $this->ignoreTables = $ignoreTables;
        $this->definitionService = new Definitions();
        $this->compareService = $compareService;
    }

    /** get jobs for all tables
     *
     * @return array
     * @throws SchemaException
     */
    public function getJobsForAllTables(): array
    {
        $this->initJobs();
        $tables = $this->schemaManager->listTables();
        foreach ($tables as $table) {
            // table ignored
            if (in_array($table->getName(), $this->ignoreTables)) {
                $error = "Table " . $table->getName() . " is configured as ignored";
                $this->jobs['Reports'][] = $error;
                continue;
            }
            // table is version
            if ($this->definitionService->isVersionTable($table)) {
                if (true !== $this->originTableExists($table)) {
                    $error = "VersionTable " . $table->getName() . " has no origin table - you should dropping it!";
                    $this->jobs['Reports'][] = $error;
                    $this->jobs['DropVersion'][] = $table->getName();
                }
                continue;
            }
            $this->findJobForTable($table);
            $this->tableNames[] = $table->getName();
        }
        return $this->jobs;
    }

    /**
     * @param string $tableName
     * @return array
     * @throws SchemaException
     */
    public function getJobForOneTable(string $tableName): array
    {
        $this->initJobs();
        // table ignored
        if (in_array($tableName, $this->ignoreTables)) {
            $error = "Table " . $tableName . " is configured as ignored";
            $this->jobs['Reports'][] = $error;
            return $this->jobs;
        }
        $tableNames = $this->schemaManager->listTableNames();
        if (!in_array($tableName, $tableNames)) {
            $error = "Table $tableName does not exists";
            throw new \Exception($error);
        }
        $this->findJobForTable($this->schema->getTable($tableName));
        return $this->jobs;
    }

    /**
     * @param Table $versionTable
     * @return bool
     */
    private function originTableExists(Table $versionTable): bool
    {
        $tName = $versionTable->getName();
        $orgName = preg_replace("/" . Definitions::VERSION_TABLE_NAME_POSTFIX . "$/", '', $tName);
        return $this->schemaManager->tablesExist([$orgName]);
    }

    /**
     * @param Table $table
     * @return bool
     * @throws SchemaException
     */
    private function findJobForTable(Table $table): bool
    {
        $tName = $table->getName();
        $versionTable = $this->getVersionTableFromOrigin($table);
        if (null === $versionTable) { // no version table
            if (true === $this->definitionService->hasTableVersionColumn($table)) {
                $this->jobs['Reports'][] = "$tName: Drop triggers, create version table, create triggers";
                $this->jobs['DropTrigger'][] = $tName;
                $this->jobs['CreateVersion'][] = $tName;
                $this->jobs['CreateTrigger'][] = $tName;
            } else {
                $this->jobs['Reports'][] = "$tName: Drop triggers";
                $this->jobs['DropTrigger'][] = $tName;
            }
            return true;
        }
        if (true === $this->definitionService->hasTableVersionColumn($table)) {
            //origin has version column, version table exists
            // comparing is necessary
            if (true === $this->compareService->compare($table, $versionTable)) {
                // nice ...
                $this->jobs['Reports'][] = "$tName: drop triggers, create triggers";
                $this->jobs['DropTrigger'][] = $tName;
                $this->jobs['CreateTrigger'][] = $tName;
                return true;
            }
            // compare not nice!
            $this->error = true;
            $compareErr = $this->compareService->getErrMessage() ?? '';
            $this->jobs['Reports'][] = "ERROR $tName: " . $compareErr;
            $this->jobs['Reports'][] = "$tName: Drop triggers";
            $this->jobs['DropTrigger'][] = $tName;
            return false;
        }
        $this->jobs['Reports'][] = "WARNING $tName: no column version, but existing version table ";
        $this->jobs['Reports'][] = "$tName: drop triggers";
        $this->jobs['DropTrigger'][] = $tName;
        $this->warning = true;
        return false;
    }

    /**
     * @param Table $table
     * @return Table|null
     * @throws SchemaException
     */
    private function getVersionTableFromOrigin(Table $table): ?Table
    {
        $tName = $table->getName();
        $versionTableName = $tName . Definitions::VERSION_TABLE_NAME_POSTFIX;
        if ($this->schemaManager->tablesExist([$versionTableName])) {
            return $this->schema->getTable($versionTableName);
        }
        return null;
    }
}
