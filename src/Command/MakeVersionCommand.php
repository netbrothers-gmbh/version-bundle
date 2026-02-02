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
            ->addOption('create-trigger', null, InputOption::VALUE_NONE, 'Drop triggers, create missing version tables, recreate triggers')
            ->addOption('drop-version', null, InputOption::VALUE_NONE, 'Drop triggers, drop version tables')
            ->addOption('drop-trigger', null, InputOption::VALUE_NONE, 'Drop triggers')
            ->addOption('sql', null, InputOption::VALUE_NONE, 'Print the SQL statements without doing anything')
            ->addOption('summary', null, InputOption::VALUE_NONE, 'Print a human readable summary of what the command would do');
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tableName = $input->getArgument('tableName');

        try {
            $jobs = (null !== $tableName)
                ? $this->jobService->getJobForOneTable($tableName)
                : $this->jobService->getJobsForAllTables();
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $sql = $this->prepareSql($jobs, $input);

        $io->newLine();
        $this->printStatistic($io, $jobs);
        $io->newLine();

        if ($input->getOption('summary')) {
            return $this->printReport($io, $jobs);
        }

        if ($input->getOption('sql')) {
            return $this->printSql($io, $sql);
        }

        $io->newLine(2);
        if ($this->jobService->hasError()) {
            $this->printReport($io, $jobs);
            $io->newLine(2);
            $io->note('Operation canceled due to errors.');
            return Command::FAILURE;
        }

        $this->executeService->execute($sql);

        if ($this->jobService->hasWarning()) {
            $this->printReport($io, $jobs);
        }

        $io->success('Operation successful.');
        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     */
    private function prepareSql(array $jobs, InputInterface $input): array
    {
        $sql = [];
        $tableNames = $this->jobService->getTableNames();

        foreach ($tableNames as $tableName) {
            if ($input->getOption('drop-version')) {
                $sql = array_merge($sql, $this->generateService->dropVersionTableAndTriggersInOriginTable($tableName));
            } elseif ($input->getOption('drop-trigger')) {
                $sql = array_merge($sql, $this->generateService->dropTriggers($tableName));
            } else {
                if (in_array($tableName, $jobs['DropTrigger'], true)) {
                    $sql = array_merge($sql, $this->generateService->dropTriggers($tableName));
                }
                if (in_array($tableName, $jobs['CreateVersion'], true)) {
                    $sql = array_merge($sql, $this->generateService->createVersionAndTriggers($tableName));
                } elseif (in_array($tableName, $jobs['CreateTrigger'], true)) {
                    $sql = array_merge($sql, $this->generateService->createTriggers($tableName));
                }
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

    private function printStatistic(SymfonyStyle $io, array $jobs): void
    {
        $io->writeln("Handling " . count($this->jobService->getTableNames()) . " tables:");
        $io->writeln("Drop Versions: " . count($jobs['DropVersion']));
        $io->writeln("Drop Triggers: " . count($jobs['DropTrigger']));
        $io->writeln("Create Version: " . count($jobs['CreateVersion']));
        $io->writeln("Create Triggers: " . count($jobs['CreateTrigger']));
    }
}
