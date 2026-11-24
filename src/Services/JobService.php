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
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use NetBrothers\VersionBundle\Services\Sql\Definitions;

final class JobService
{
    /** @var string[] */
    private array $ignoreTables;

    /**
     * @var array{
     *      'DropTrigger': string[],
     *      'CreateTrigger': string[],
     *      'DropVersion': string[],
     *      'CreateVersion': string[],
     *      'Reports': string[],
     * }
     */
    private array $jobs = [
        'DropTrigger' => [],
        'CreateTrigger' => [],
        'DropVersion' => [],
        'CreateVersion' => [],
        'Reports' => [],
    ];

    /** @var string[] */
    private array $tableNames = [];
    private bool $warning = false;
    private bool $error = false;
    private AbstractSchemaManager $schemaManager;
    private Schema $schema;

    public function hasError(): bool
    {
        return $this->error;
    }

    public function hasWarning(): bool
    {
        return $this->warning;
    }

    /** @return string[] */
    public function getReport(): array
    {
        return $this->jobs['Reports'];
    }

    /** @return string[] */
    public function getTableNames(): array
    {
        return $this->tableNames;
    }

    public function addTableName(string $tableName): void
    {
        $this->tableNames[] = $tableName;
    }

    private function initJobs(): void
    {
        $this->jobs = [
            'DropTrigger' => [],
            'CreateTrigger' => [],
            'DropVersion' => [],
            'CreateVersion' => [],
            'Reports' => [],
        ];
        $this->tableNames = [];
        $this->warning = false;
        $this->error = false;
    }

    /**
     * @throws Exception
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompareService $compareService,
        private readonly VersionConfigService $configService
    ) {
        $connection = $this->entityManager->getConnection();
        $connection->getConfiguration()->setSchemaAssetsFilter(static fn () => true);
        $this->schemaManager = $connection->createSchemaManager();
        $this->schema = $this->schemaManager->introspectSchema();
        $this->ignoreTables = $this->configService->getIgnoreTables();
    }

    /**
     * get jobs for all tables
     *
     * @return array
     * @throws SchemaException
     */
    public function getJobsForAllTables(): array
    {
        $this->initJobs();
        $tables = $this->schema->getTables();
        foreach ($tables as $table) {
            $tableName = $table->getObjectName()->getUnqualifiedName()->getValue();
            if (in_array($tableName, $this->ignoreTables, true)) {
                $this->jobs['Reports'][] = "Table " . $tableName . " is configured as ignored";
                continue;
            }
            if ($this->isVersionTable($table)) {
                if (!$this->originTableExists($table)) {
                    $this->jobs['Reports'][] = "WARNING $tableName: orphaned version table (no origin table) - will be dropped";
                    $this->jobs['DropVersion'][] = $tableName;
                    $this->warning = true;
                }
                continue;
            }
            $this->findJobForTable($table);
            $this->tableNames[] = $tableName;
        }
        return $this->jobs;
    }

    /**
     * @throws SchemaException|\InvalidArgumentException
     */
    public function getJobForOneTable(string $tableName): array
    {
        $this->initJobs();
        if (in_array($tableName, $this->ignoreTables, true)) {
            $this->jobs['Reports'][] = "Table " . $tableName . " is configured as ignored";
            return $this->jobs;
        }
        if (!$this->schema->hasTable($tableName)) {
            throw new \InvalidArgumentException(sprintf('Table "%s" does not exist.', $tableName));
        }

        $table = $this->schema->getTable($tableName);
        if ($this->isVersionTable($table)) {
            if (!$this->originTableExists($table)) {
                $this->jobs['Reports'][] = "WARNING $tableName: orphaned version table (no origin table) - will be dropped";
                $this->jobs['DropVersion'][] = $tableName;
                $this->warning = true;
                return $this->jobs;
            }
            throw new \InvalidArgumentException(sprintf(
                'Table "%s" is a version table. Please specify the origin table "%s" instead.',
                $tableName,
                $this->getOriginTableName($table),
            ));
        }

        $this->findJobForTable($table);
        $this->tableNames[] = $tableName;
        return $this->jobs;
    }

    private function originTableExists(Table $versionTable): bool
    {
        return $this->schema->hasTable($this->getOriginTableName($versionTable));
    }

    private function getOriginTableName(Table $versionTable): string
    {
        $tName = $versionTable->getObjectName()->getUnqualifiedName()->getValue();
        return (string) preg_replace('/' . preg_quote(Definitions::VERSION_TABLE_NAME_POSTFIX, '/') . '$/', '', $tName);
    }

    /**
     * @throws SchemaException
     */
    private function findJobForTable(Table $table): void
    {
        $tName = $table->getObjectName()->getUnqualifiedName()->getValue();
        $versionTable = $this->getVersionTableFromOrigin($table);
        if (null === $versionTable) {
            if ($this->hasTableVersionColumn($table)) {
                $this->jobs['Reports'][] = "$tName: Drop triggers, create version table, create triggers";
                $this->jobs['DropTrigger'][] = $tName;
                $this->jobs['CreateVersion'][] = $tName;
                $this->jobs['CreateTrigger'][] = $tName;
            } else {
                $this->jobs['Reports'][] = "$tName: Drop triggers";
                $this->jobs['DropTrigger'][] = $tName;
            }
            return;
        }
        if ($this->hasTableVersionColumn($table)) {
            if ($this->compareService->compare($table, $versionTable)) {
                $this->jobs['Reports'][] = "$tName: drop triggers, create triggers";
                $this->jobs['DropTrigger'][] = $tName;
                $this->jobs['CreateTrigger'][] = $tName;
                return;
            }
            $this->error = true;
            $compareErr = $this->compareService->getErrMessage() ?? '';
            $this->jobs['Reports'][] = "ERROR $tName: " . $compareErr;
            $this->jobs['Reports'][] = "$tName: Drop triggers";
            $this->jobs['DropTrigger'][] = $tName;
            return;
        }
        $this->jobs['Reports'][] = "WARNING $tName: no column version, but existing version table";
        $this->jobs['Reports'][] = "$tName: drop triggers";
        $this->jobs['DropTrigger'][] = $tName;
        $this->warning = true;
    }

    /**
     * @throws SchemaException
     */
    private function getVersionTableFromOrigin(Table $table): ?Table
    {
        $tName = $table->getObjectName()->getUnqualifiedName()->getValue();
        $versionTableName = $tName . Definitions::VERSION_TABLE_NAME_POSTFIX;
        if ($this->schema->hasTable($versionTableName)) {
            return $this->schema->getTable($versionTableName);
        }
        return null;
    }

    private function hasTableVersionColumn(Table $table): bool
    {
        return $table->hasColumn('version');
    }

    private function isVersionTable(Table $table): bool
    {
        return str_ends_with(
            $table->getObjectName()->getUnqualifiedName()->getValue(),
            Definitions::VERSION_TABLE_NAME_POSTFIX,
        );
    }
}
