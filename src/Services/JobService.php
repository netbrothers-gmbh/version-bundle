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
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use NetBrothers\VersionBundle\Services\Report\TableJobEntry;
use NetBrothers\VersionBundle\Services\Report\TableStatus;
use NetBrothers\VersionBundle\Services\Schema\TableSyncPlan;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaSyncer;
use NetBrothers\VersionBundle\Services\Sql\Definitions;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;

final class JobService
{
    /** @var string[] */
    private readonly array $ignoreTables;

    /**
     * @var array{
     *      'DropTrigger': string[],
     *      'CreateTrigger': string[],
     *      'DropVersion': string[],
     *      'CreateVersion': string[],
     *      'UpdateVersion': array<string, TableSyncPlan>,
     * }
     */
    private array $jobs = [
        'DropTrigger' => [],
        'CreateTrigger' => [],
        'DropVersion' => [],
        'CreateVersion' => [],
        'UpdateVersion' => [],
    ];

    /** @var string[] */
    private array $tableNames = [];

    /** @var TableJobEntry[] */
    private array $entries = [];

    /** @var array<string, Table> */
    private array $tables = [];

    /** @var array<string, string> Map of expected version table name => origin table name */
    private array $versionToOriginMap = [];

    public function __construct(
        private readonly DatabaseConnectionProvider $connectionProvider,
        private readonly VersionTableSchemaSyncer $schemaSyncer,
        VersionConfigService $configService,
        private readonly IdentifierHelper $identifierHelper,
    ) {
        $this->ignoreTables = $configService->getIgnoreTables();
    }

    /** @return string[] */
    public function getTableNames(): array
    {
        return $this->tableNames;
    }

    /** @return TableJobEntry[] */
    public function getTableEntries(): array
    {
        return $this->entries;
    }

    /**
     * Get jobs for all tables in the database.
     *
     * @return array{
     *      'DropTrigger': string[],
     *      'CreateTrigger': string[],
     *      'DropVersion': string[],
     *      'CreateVersion': string[],
     *      'UpdateVersion': array<string, TableSyncPlan>,
     * }
     * @throws Exception|\RuntimeException
     */
    public function getJobsForAllTables(): array
    {
        $this->initJobs();
        $this->loadTables();
        $comparator = $this->connectionProvider->createComparator();
        $platform = $this->connectionProvider->getPlatform();

        foreach ($this->tables as $tableName => $table) {
            if (in_array($tableName, $this->ignoreTables, true)) {
                $this->entries[] = new TableJobEntry(
                    tableName: $tableName,
                    status: TableStatus::IGNORED,
                    note: 'Configured in ignore_tables',
                );
                continue;
            }
            if ($this->isVersionTableName($tableName)) {
                if (!isset($this->versionToOriginMap[$tableName])) {
                    $this->jobs['DropVersion'][] = $tableName;
                    $this->entries[] = new TableJobEntry(
                        tableName: $tableName,
                        status: TableStatus::ORPHANED,
                        versionTableName: $tableName,
                        note: 'No origin table found; version table will be dropped',
                    );
                }
                continue;
            }
            $this->findJobForTable($tableName, $table, $comparator, $platform);
            $this->tableNames[] = $tableName;
        }

        return $this->jobs;
    }

    /**
     * @return array{
     *      'DropTrigger': string[],
     *      'CreateTrigger': string[],
     *      'DropVersion': string[],
     *      'CreateVersion': string[],
     *      'UpdateVersion': array<string, TableSyncPlan>,
     * }
     * @throws Exception|\InvalidArgumentException|\RuntimeException
     */
    public function getJobForOneTable(string $tableName): array
    {
        $this->initJobs();
        $this->loadTables();

        if (in_array($tableName, $this->ignoreTables, true)) {
            $this->entries[] = new TableJobEntry(
                tableName: $tableName,
                status: TableStatus::IGNORED,
                note: 'Configured in ignore_tables',
            );
            return $this->jobs;
        }
        if (!isset($this->tables[$tableName])) {
            throw new \InvalidArgumentException(sprintf('Table "%s" does not exist.', $tableName));
        }

        if ($this->isVersionTableName($tableName)) {
            if (!isset($this->versionToOriginMap[$tableName])) {
                $this->jobs['DropVersion'][] = $tableName;
                $this->entries[] = new TableJobEntry(
                    tableName: $tableName,
                    status: TableStatus::ORPHANED,
                    versionTableName: $tableName,
                    note: 'No origin table found; version table will be dropped',
                );
                return $this->jobs;
            }
            throw new \InvalidArgumentException(sprintf(
                'Table "%s" is a version table. Please specify the origin table "%s" instead.',
                $tableName,
                $this->versionToOriginMap[$tableName],
            ));
        }

        $comparator = $this->connectionProvider->createComparator();
        $platform = $this->connectionProvider->getPlatform();

        $this->findJobForTable($tableName, $this->tables[$tableName], $comparator, $platform);
        $this->tableNames[] = $tableName;

        return $this->jobs;
    }

    private function initJobs(): void
    {
        $this->jobs = [
            'DropTrigger' => [],
            'CreateTrigger' => [],
            'DropVersion' => [],
            'CreateVersion' => [],
            'UpdateVersion' => [],
        ];
        $this->tableNames = [];
        $this->entries = [];
    }

    /**
     * @throws Exception|\RuntimeException
     */
    private function loadTables(): void
    {
        $this->tables = $this->connectionProvider->introspectAllTables();
        $this->versionToOriginMap = [];

        foreach ($this->tables as $tableName => $table) {
            if (!$this->isVersionTableName($tableName)) {
                $expectedVersionTableName = $this->identifierHelper->buildVersionTableName($tableName);
                $this->versionToOriginMap[$expectedVersionTableName] = $tableName;
            }
        }
    }

    /**
     * @throws Exception
     */
    private function findJobForTable(
        string $tName,
        Table $table,
        Comparator $comparator,
        AbstractPlatform $platform,
    ): void {
        $versionTableName = $this->identifierHelper->buildVersionTableName($tName);
        $versionTable = $this->tables[$versionTableName] ?? null;

        if ($versionTable === null) {
            if ($this->hasTableVersionColumn($table)) {
                $this->jobs['DropTrigger'][] = $tName;
                $this->jobs['CreateVersion'][] = $tName;
                $this->jobs['CreateTrigger'][] = $tName;
                $this->entries[] = new TableJobEntry(
                    tableName: $tName,
                    status: TableStatus::CREATE,
                    versionTableName: $versionTableName,
                    note: sprintf('Create %s, backfill rows, create triggers', $versionTableName),
                );
            } else {
                $this->jobs['DropTrigger'][] = $tName;
                $this->entries[] = new TableJobEntry(
                    tableName: $tName,
                    status: TableStatus::NO_VERSION,
                    note: 'No version column (drop any stale triggers)',
                );
            }
            return;
        }

        if ($this->hasTableVersionColumn($table)) {
            $syncPlan = $this->schemaSyncer->planSync($table, $versionTable, $comparator, $platform);
            $this->jobs['DropTrigger'][] = $tName;
            $this->jobs['CreateTrigger'][] = $tName;

            if ($syncPlan->hasChanges()) {
                $this->jobs['UpdateVersion'][$tName] = $syncPlan;
                $this->entries[] = new TableJobEntry(
                    tableName: $tName,
                    status: TableStatus::UPDATE,
                    versionTableName: $versionTableName,
                    syncPlan: $syncPlan,
                );
            } else {
                $this->entries[] = new TableJobEntry(
                    tableName: $tName,
                    status: TableStatus::UP_TO_DATE,
                    versionTableName: $versionTableName,
                    note: 'Schema in sync; recreate triggers',
                );
            }

            return;
        }

        $this->jobs['DropTrigger'][] = $tName;
        $this->entries[] = new TableJobEntry(
            tableName: $tName,
            status: TableStatus::MISSING_VERSION_COLUMN,
            versionTableName: $versionTableName,
            note: sprintf('No version column, but %s still exists (dropping triggers only)', $versionTableName),
        );
    }

    private function hasTableVersionColumn(Table $table): bool
    {
        return $table->hasColumn('version');
    }

    private function isVersionTableName(string $tableName): bool
    {
        return str_ends_with($tableName, Definitions::VERSION_TABLE_NAME_POSTFIX);
    }
}
