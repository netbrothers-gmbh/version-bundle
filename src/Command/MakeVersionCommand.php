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

namespace NetBrothers\VersionBundle\Command;

use Doctrine\DBAL\Exception;
use NetBrothers\VersionBundle\Services\DatabaseConnectionProvider;
use NetBrothers\VersionBundle\Services\GenerateService;
use NetBrothers\VersionBundle\Services\JobService;
use NetBrothers\VersionBundle\Services\Report\ConsoleReportRenderer;
use NetBrothers\VersionBundle\Services\Report\ExecutionMode;
use NetBrothers\VersionBundle\Services\Schema\TableSyncPlan;
use NetBrothers\VersionBundle\Services\Sql\ExecuteService;
use NetBrothers\VersionBundle\Services\Sql\SqlScriptFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'netbrothers:version',
    description: 'Create, synchronize, and manage MariaDB/MySQL version tables and triggers.',
)]
final class MakeVersionCommand extends Command
{
    public function __construct(
        private readonly DatabaseConnectionProvider $connectionProvider,
        private readonly JobService $jobService,
        private readonly GenerateService $generateService,
        private readonly ExecuteService $executeService,
        private readonly ConsoleReportRenderer $reportRenderer,
        private readonly SqlScriptFormatter $sqlScriptFormatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tableName', InputArgument::OPTIONAL, 'Process only this origin table')
            ->addOption('drop-version', null, InputOption::VALUE_NONE, 'Drop versioning triggers and `_version` tables')
            ->addOption('drop-trigger', null, InputOption::VALUE_NONE, 'Drop versioning triggers only (keep `_version` tables)')
            ->addOption('sql', null, InputOption::VALUE_NONE, 'Output executable SQL statements to stdout without modifying the database')
            ->addOption('summary', null, InputOption::VALUE_NONE, 'Display the planned table changes and statistics without executing SQL')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'The Doctrine entity manager to use for this command')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command manages <comment>_version</comment> tables and versioning triggers for MariaDB/MySQL tables containing a <comment>version</comment> column.

<info>Recommended Migration Workflow:</info>
  1. Run Doctrine migrations (origin tables only):
     <comment>php bin/console doctrine:migrations:migrate</comment>
  2. Synchronize version tables and recreate triggers:
     <comment>php bin/console %command.name%</comment>

  For every versioned table, the default mode executes:
    - Drop existing triggers (including legacy v4 <comment>BEVOR_*</comment> triggers)
    - Create missing <comment>_version</comment> tables (and safely backfill existing rows)
    - Automatically synchronize existing <comment>_version</comment> tables (<comment>ADD</comment>, <comment>MODIFY</comment>, <comment>DROP</comment> columns)
    - Recreate <comment>BEFORE/AFTER INSERT</comment> and <comment>BEFORE/AFTER UPDATE</comment> triggers

  All non-primary-key columns in <comment>_version</comment> tables are maintained as nullable (<comment>NULL</comment>) so adding
  <comment>NOT NULL</comment> columns without defaults or tightening <comment>NULL -> NOT NULL</comment> never fails on historical rows.
  Columns removed from origin tables are dropped from <comment>_version</comment> tables as well.

<info>Data-Migration Edge Case:</info>
  If a Doctrine migration executes <comment>INSERT</comment> or <comment>UPDATE</comment> statements on a versioned table
  <comment>after</comment> altering its columns, drop triggers beforehand:
    <comment>php bin/console %command.name% --drop-trigger</comment>
    <comment>php bin/console doctrine:migrations:migrate</comment>
    <comment>php bin/console %command.name%</comment>

<info>Examples:</info>
  <comment>php bin/console %command.name%</comment>                          Synchronize all tables and recreate triggers
  <comment>php bin/console %command.name% protocol</comment>                 Synchronize only the "protocol" table
  <comment>php bin/console %command.name% --summary</comment>                Dry-run: show table plan, column diffs, and counters
  <comment>php bin/console %command.name% --sql > version_sync.sql</comment> Dry-run: export clean executable SQL script
  <comment>php bin/console %command.name% --drop-trigger</comment>           Drop triggers only (preserve version history)
  <comment>php bin/console %command.name% --drop-version</comment>           Drop triggers and all `_version` tables
  <comment>php bin/console %command.name% --em=secondary</comment>           Run against a specific Doctrine EntityManager
HELP);
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDropVersion = (bool) $input->getOption('drop-version');
        $isDropTrigger = (bool) $input->getOption('drop-trigger');
        $isSql = (bool) $input->getOption('sql');
        $isSummary = (bool) $input->getOption('summary');

        if ($isDropVersion && $isDropTrigger) {
            $io->error('Options --drop-trigger and --drop-version cannot be used together.');
            return Command::FAILURE;
        }

        if ($isSql && $isSummary) {
            $io->error('Options --sql and --summary cannot be used together.');
            return Command::FAILURE;
        }

        $mode = match (true) {
            $isDropVersion => ExecutionMode::DROP_VERSION,
            $isDropTrigger => ExecutionMode::DROP_TRIGGER,
            default => ExecutionMode::SYNC,
        };

        $emName = $input->getOption('em');
        $this->connectionProvider->selectEntityManager(is_string($emName) && $emName !== '' ? $emName : null);

        $tableName = $input->getArgument('tableName');

        try {
            $jobs = (null !== $tableName)
                ? $this->jobService->getJobForOneTable((string) $tableName)
                : $this->jobService->getJobsForAllTables();
            $sql = $this->prepareSql($jobs, $mode);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($isSql) {
            $output->write($this->sqlScriptFormatter->format($sql));
            return Command::SUCCESS;
        }

        $entries = $this->jobService->getTableEntries();

        if ($isSummary) {
            $this->reportRenderer->render($io, $entries, $mode, $sql, true);
            $io->note('Dry-run complete (--summary). No SQL statements were executed.');
            return Command::SUCCESS;
        }

        $this->reportRenderer->render($io, $entries, $mode, $sql, false);

        if ($mode === ExecutionMode::SYNC && $this->jobService->hasError()) {
            $io->error('Operation canceled due to errors.');
            return Command::FAILURE;
        }

        $this->executeService->execute($sql);

        $io->success(sprintf('Operation completed (%d SQL statements executed).', count($sql)));
        return Command::SUCCESS;
    }

    /**
     * @param array{
     *      'DropTrigger': string[],
     *      'CreateTrigger': string[],
     *      'DropVersion': string[],
     *      'CreateVersion': string[],
     *      'UpdateVersion': array<string, TableSyncPlan>,
     *      'Reports': string[],
     * } $jobs
     * @return string[]
     * @throws Exception
     */
    private function prepareSql(array $jobs, ExecutionMode $mode): array
    {
        $sql = [];
        $tableNames = $this->jobService->getTableNames();

        if ($mode === ExecutionMode::DROP_VERSION) {
            foreach ($tableNames as $tableName) {
                $sql = array_merge($sql, $this->generateService->dropVersionTableAndTriggersInOriginTable($tableName));
            }
            foreach ($jobs['DropVersion'] as $orphanedVersionTable) {
                $sql = array_merge($sql, $this->generateService->dropOrphanedVersionTable($orphanedVersionTable));
            }
            return $sql;
        }

        if ($mode === ExecutionMode::DROP_TRIGGER) {
            foreach ($tableNames as $tableName) {
                $sql = array_merge($sql, $this->generateService->dropTriggers($tableName));
            }
            return $sql;
        }

        foreach ($jobs['DropVersion'] as $orphanedVersionTable) {
            $sql = array_merge($sql, $this->generateService->dropOrphanedVersionTable($orphanedVersionTable));
        }

        foreach ($tableNames as $tableName) {
            if (in_array($tableName, $jobs['DropTrigger'], true)) {
                $sql = array_merge($sql, $this->generateService->dropTriggers($tableName));
            }
            if (in_array($tableName, $jobs['CreateVersion'], true)) {
                $sql = array_merge($sql, $this->generateService->createVersionAndTriggers($tableName));
            } else {
                if (isset($jobs['UpdateVersion'][$tableName])) {
                    $sql = array_merge($sql, $jobs['UpdateVersion'][$tableName]->sql);
                }
                if (in_array($tableName, $jobs['CreateTrigger'], true)) {
                    $sql = array_merge($sql, $this->generateService->createTriggers($tableName));
                }
            }
        }

        return $sql;
    }
}
