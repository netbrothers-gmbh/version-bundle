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

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use NetBrothers\VersionBundle\Services\Sql\CreateTrigger;
use NetBrothers\VersionBundle\Services\Sql\Definitions;
use NetBrothers\VersionBundle\Services\Sql\DropTrigger;
use NetBrothers\VersionBundle\Services\Sql\VersionTable;

final class GenerateService
{
    private AbstractSchemaManager $schemaManager;
    private string $databaseName;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VersionTable $versionTableService,
        private readonly DropTrigger $dropTriggerService,
        private readonly CreateTrigger $createTriggerService
    ) {
        $connection = $this->entityManager->getConnection();
        $connection->getConfiguration()->setSchemaAssetsFilter(static fn () => true);
        $this->schemaManager = $connection->createSchemaManager();
        $this->databaseName = $connection->getDatabase();
    }

    /**
     * dropping triggers in origin table / dropping version table
     *
     * @param string $tableName name of origin table
     * @return string[]
     * @throws Exception
     */
    public function dropVersionTableAndTriggersInOriginTable(string $tableName): array
    {
        $sql = [];
        $versionTableName = $tableName . Definitions::VERSION_TABLE_NAME_POSTFIX;

        if ($this->schemaManager->tableExists($versionTableName)) {
            $sql = $this->dropTriggers($tableName);
            $sql[] = $this->versionTableService->dropVersionTable($this->databaseName, $tableName);
        }
        return $sql;
    }

    /**
     * dropping triggers in origin table
     *
     * @param string $tableName name of origin table
     * @return string[]
     * @throws Exception
     */
    public function dropTriggers(string $tableName): array
    {
        if ($this->schemaManager->tableExists($tableName)) {
            return $this->dropTriggerService->getSql($this->databaseName, $tableName);
        }
        return [];
    }

    /**
     * @param string $tableName name of origin table
     * @return string[]
     * @throws Exception
     */
    public function createVersionAndTriggers(string $tableName): array
    {
        $sql = [];
        if ($this->schemaManager->tableExists($tableName)) {
            $versionTableName = $tableName . Definitions::VERSION_TABLE_NAME_POSTFIX;
            if (!$this->schemaManager->tableExists($versionTableName)) {
                $sql = $this->versionTableService->getSqlCreate($this->databaseName, $tableName);
                foreach ($this->createTriggerService->getSql($this->databaseName, $tableName) as $query) {
                    $sql[] = $query;
                }
            }
        }
        return $sql;
    }

    /**
     * @param string $tableName
     * @return string[]
     * @throws Exception
     */
    public function createTriggers(string $tableName): array
    {
        if ($this->schemaManager->tableExists($tableName)) {
            return $this->createTriggerService->getSql($this->databaseName, $tableName);
        }
        return [];
    }
}
