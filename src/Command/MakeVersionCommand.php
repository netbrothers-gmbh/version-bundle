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
use NetBrothers\VersionBundle\Services\GenerateService;
use NetBrothers\VersionBundle\Services\JobService;
use NetBrothers\VersionBundle\Services\Sql\ExecuteService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'netbrothers:version',
    description: 'Create version tables and triggers.',
)]
final class MakeVersionCommand extends Command
{
    public function __construct(
        private readonly JobService $jobService,
        private readonly GenerateService $generateService,
        private readonly ExecuteService $executeService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tableName', InputArgument::OPTIONAL, 'Work only on this table')
            ->addOption('drop-version', null, InputOption::VALUE_NONE, 'Drop triggers and version tables')
            ->addOption('drop-trigger', null, InputOption::VALUE_NONE, 'Drop triggers only')
            ->addOption('sql', null, InputOption::VALUE_NONE, 'Print the SQL statements without doing anything')
            ->addOption('summary', null, InputOption::VALUE_NONE, 'Print a human readable summary of what the command would do');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDropVersion = (bool) $input->getOption('drop-version');
        $isDropTrigger = (bool) $input->getOption('drop-trigger');

        if ($isDropVersion && $isDropTrigger) {
            $io->error('Options --drop-trigger and --drop-version cannot be used together.');
            return Command::FAILURE;
        }

        $tableName = $input->getArgument('tableName');

        try {
            $jobs = (null !== $tableName)
                ? $this->jobService->getJobForOneTable((string) $tableName)
                : $this->jobService->getJobsForAllTables();
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $sql = $this->prepareSql($jobs, $isDropVersion, $isDropTrigger);

        $io->newLine();
        $this->printStatistic($io, $jobs, $isDropVersion, $isDropTrigger);
        $io->newLine();

        if ($input->getOption('summary')) {
            return $this->printReport($io, $jobs);
        }

        if ($input->getOption('sql')) {
            return $this->printSql($io, $sql);
        }

        $io->newLine(2);
        if (!$isDropVersion && !$isDropTrigger && $this->jobService->hasError()) {
            $this->printReport($io, $jobs);
            $io->newLine(2);
            $io->note('Operation canceled due to errors.');
            return Command::FAILURE;
        }

        $this->executeService->execute($sql);

        if (!$isDropVersion && !$isDropTrigger && $this->jobService->hasWarning()) {
            $this->printReport($io, $jobs);
        }

        $io->success('Operation successful.');
        return Command::SUCCESS;
    }

    /**
     * @param array{
     *      'DropTrigger': string[],
     *      'CreateTrigger': string[],
     *      'DropVersion': string[],
     *      'CreateVersion': string[],
     *      'Reports': string[],
     * } $jobs
     * @return string[]
     * @throws Exception
     */
    private function prepareSql(array $jobs, bool $isDropVersion, bool $isDropTrigger): array
    {
        $sql = [];
        $tableNames = $this->jobService->getTableNames();

        if ($isDropVersion) {
            foreach ($tableNames as $tableName) {
                $sql = array_merge($sql, $this->generateService->dropVersionTableAndTriggersInOriginTable($tableName));
            }
            foreach ($jobs['DropVersion'] as $orphanedVersionTable) {
                $sql = array_merge($sql, $this->generateService->dropOrphanedVersionTable($orphanedVersionTable));
            }
            return $sql;
        }

        if ($isDropTrigger) {
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
            } elseif (in_array($tableName, $jobs['CreateTrigger'], true)) {
                $sql = array_merge($sql, $this->generateService->createTriggers($tableName));
            }
        }
        return $sql;
    }

    private function printReport(SymfonyStyle $io, array $jobs): int
    {
        $errors = [];
        $warnings = [];
        foreach ($jobs['Reports'] as $message) {
            if (str_starts_with($message, 'ERROR')) {
                $errors[] = $message;
            } elseif (str_starts_with($message, 'WARNING')) {
                $warnings[] = $message;
            } else {
                $io->writeln($message);
            }
        }
        if (!empty($warnings)) {
            $io->warning(implode(PHP_EOL, $warnings));
        }
        if (!empty($errors)) {
            $io->error(implode(PHP_EOL, $errors));
        }
        return Command::SUCCESS;
    }

    private function printSql(SymfonyStyle $io, array $sql): int
    {
        foreach ($sql as $query) {
            $io->writeln($query);
        }
        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     */
    private function printStatistic(SymfonyStyle $io, array $jobs, bool $isDropVersion, bool $isDropTrigger): void
    {
        $tableNames = $this->jobService->getTableNames();

        if ($isDropVersion) {
            $tablesWithVersion = 0;
            foreach ($tableNames as $tableName) {
                if ($this->generateService->hasVersionTable($tableName)) {
                    $tablesWithVersion++;
                }
            }
            $io->writeln('Handling ' . (count($tableNames) + count($jobs['DropVersion'])) . ' tables:');
            $io->writeln('Drop Versions: ' . ($tablesWithVersion + count($jobs['DropVersion'])));
            $io->writeln('Drop Triggers: ' . $tablesWithVersion);
            $io->writeln('Create Version: 0');
            $io->writeln('Create Triggers: 0');
            return;
        }

        if ($isDropTrigger) {
            $io->writeln('Handling ' . count($tableNames) . ' tables:');
            $io->writeln('Drop Versions: 0');
            $io->writeln('Drop Triggers: ' . count($tableNames));
            $io->writeln('Create Version: 0');
            $io->writeln('Create Triggers: 0');
            return;
        }

        $io->writeln('Handling ' . (count($tableNames) + count($jobs['DropVersion'])) . ' tables:');
        $io->writeln('Drop Versions: ' . count($jobs['DropVersion']));
        $io->writeln('Drop Triggers: ' . count($jobs['DropTrigger']));
        $io->writeln('Create Version: ' . count($jobs['CreateVersion']));
        $io->writeln('Create Triggers: ' . count($jobs['CreateTrigger']));
    }
}
