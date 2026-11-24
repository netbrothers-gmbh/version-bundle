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
use NetBrothers\VersionBundle\Services\Sql\CreateTrigger;
use NetBrothers\VersionBundle\Services\Sql\DropTrigger;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;
use NetBrothers\VersionBundle\Services\Sql\VersionTable;

final readonly class GenerateService
{
    public function __construct(
        private DatabaseConnectionProvider $connectionProvider,
        private VersionTable $versionTableService,
        private DropTrigger $dropTriggerService,
        private CreateTrigger $createTriggerService,
        private IdentifierHelper $identifierHelper,
    ) {
    }

    /**
     * Dropping triggers on origin table and dropping the version table.
     *
     * @param string $tableName Name of origin table
     * @return string[]
     * @throws Exception|\RuntimeException
     */
    public function dropVersionTableAndTriggersInOriginTable(string $tableName): array
    {
        $sql = [];
        $versionTableName = $this->identifierHelper->buildVersionTableName($tableName);

        if ($this->connectionProvider->tableExists($versionTableName)) {
            $sql = $this->dropTriggers($tableName);
            $sql[] = $this->versionTableService->dropVersionTable(
                $versionTableName,
                $this->connectionProvider->getPlatform(),
            );
        }

        return $sql;
    }

    /**
     * Drop an orphaned version table directly by its full table name.
     *
     * @param string $versionTableName Full name of orphaned version table
     * @return string[]
     * @throws Exception|\RuntimeException
     */
    public function dropOrphanedVersionTable(string $versionTableName): array
    {
        if ($this->connectionProvider->tableExists($versionTableName)) {
            return [
                $this->versionTableService->dropVersionTable(
                    $versionTableName,
                    $this->connectionProvider->getPlatform(),
                ),
            ];
        }

        return [];
    }

    /**
     * Dropping triggers on origin table.
     *
     * @param string $tableName Name of origin table
     * @return string[]
     * @throws Exception|\RuntimeException
     */
    public function dropTriggers(string $tableName): array
    {
        if ($this->connectionProvider->tableExists($tableName)) {
            return $this->dropTriggerService->getSql(
                $this->connectionProvider->getDatabaseName(),
                $tableName,
                $this->connectionProvider->getPlatform(),
            );
        }

        return [];
    }

    /**
     * Creating version table, backfilling existing rows, and creating triggers.
     *
     * @param string $tableName Name of origin table
     * @return string[]
     * @throws Exception|\RuntimeException
     */
    public function createVersionAndTriggers(string $tableName): array
    {
        $sql = [];
        if ($this->connectionProvider->tableExists($tableName)) {
            $versionTableName = $this->identifierHelper->buildVersionTableName($tableName);
            if (!$this->connectionProvider->tableExists($versionTableName)) {
                $originTable = $this->connectionProvider->introspectTable($tableName);
                $platform = $this->connectionProvider->getPlatform();
                $databaseName = $this->connectionProvider->getDatabaseName();

                $sql = $this->versionTableService->getSqlCreate($originTable, $platform);
                foreach ($this->createTriggerService->getSql($databaseName, $originTable, $platform) as $query) {
                    $sql[] = $query;
                }
            }
        }

        return $sql;
    }

    /**
     * Creating triggers on origin table.
     *
     * @param string $tableName Name of origin table
     * @return string[]
     * @throws Exception|\RuntimeException
     */
    public function createTriggers(string $tableName): array
    {
        if ($this->connectionProvider->tableExists($tableName)) {
            $originTable = $this->connectionProvider->introspectTable($tableName);

            return $this->createTriggerService->getSql(
                $this->connectionProvider->getDatabaseName(),
                $originTable,
                $this->connectionProvider->getPlatform(),
            );
        }

        return [];
    }
}
