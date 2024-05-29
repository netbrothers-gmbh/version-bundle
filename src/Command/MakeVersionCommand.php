<?php

/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 */

namespace NetBrothers\VersionBundle\Command;

use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\ORM\EntityManagerInterface;
use NetBrothers\VersionBundle\Services\CompareService;
use NetBrothers\VersionBundle\Services\GenerateService;
use NetBrothers\VersionBundle\Services\JobService;
use NetBrothers\VersionBundle\Services\Sql\ExecuteService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MakeVersionCommand
 * @package NetBrothers\VersionBundle\Command
 */
#[AsCommand(
    name: 'netbrothers:version',
    description: 'Create version tables and triggers.',
)]
class MakeVersionCommand extends Command
{
    /** @inheritdoc */
    protected static $defaultName = 'netbrothers:version';

    private JobService $jobService;

    private GenerateService $generateService;

    /** @var array<int, string> */
    private array $sql = [];

    /** @var array  */
    private array $jobs = [];

    private ExecuteService $executeService;

    private EntityManagerInterface $entityManager;

    /** configuration */
    protected function configure()
    {
        $this
            ->setDescription('Create version tables and triggers.')
            ->setHelp('See vendor/netbrothers-gmbh/version-bundle/README.md')
            ->addArgument(
                'tableName',
                InputArgument::OPTIONAL,
                'work only on this table'
            )
            ->addOption(
                'create-trigger',
                null,
                InputOption::VALUE_NONE,
                'drop triggers, create missing version tables, recreate triggers'
            )
            ->addOption(
                'drop-version',
                null,
                InputOption::VALUE_NONE,
                'drop triggers, drop version tables'
            )
            ->addOption(
                'drop-trigger',
                null,
                InputOption::VALUE_NONE,
                'drop triggers'
            )
            ->addOption(
                'sql',
                null,
                InputOption::VALUE_NONE,
                'print the SQL statements without doing anything'
            )
            ->addOption(
                'summary',
                null,
                InputOption::VALUE_NONE,
                'print a human readable summary of what the command would do'
            );
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        ?EntityManagerInterface $entityManager = null,
        array $ignoreTables = [],
        array $excludeColumnNames = [],
        bool $initLater = false,
        string $name = null
    ) {
        parent::__construct($name);
        if ($initLater) {
            return;
        }
        $this->initCommand(
            $entityManager,
            $ignoreTables,
            $excludeColumnNames
        );
    }

    /**
     * Method for an initialization after the command has been constructed.
     * Needed for the standalone version.
     * 
     * @param EntityManagerInterface|null $entityManager
     * @param array $ignoreTables
     * @param array $excludeColumnNames
     * @return void
     */
    protected function initCommand(
        EntityManagerInterface $entityManager = null,
        array $ignoreTables = [],
        array $excludeColumnNames = []
    ): void
    {
        $this->entityManager = $entityManager;
        $con = $this->entityManager->getConnection();
        $con->getConfiguration()->setSchemaAssetsFilter(null);
        $schemaManager = $con->createSchemaManager();
        $compareService = new CompareService($excludeColumnNames);
        $this->jobService = new JobService($schemaManager, $compareService, $ignoreTables);
        $this->generateService = new GenerateService($schemaManager, $con->getDatabase());
        $this->executeService = new ExecuteService($entityManager);
    }

    /**
     * @param string|null $tableName
     * @throws SchemaException
     */
    private function setJobs(string $tableName = null): void
    {
        $this->jobs = (null !== $tableName)
            ? $this->jobService->getJobForOneTable($tableName)
            : $this->jobService->getJobsForAllTables();
    }

    /**
     * @param InputInterface $input
     */
    private function prepareSqlForAllTable(InputInterface $input): void
    {
        foreach ($this->jobService->getTableNames() as $tableName) {
            $sql = $this->prepareSqlForOneTable($tableName, $input);
            foreach ($sql as $query) {
                $this->sql[] = $query;
            }
        }
    }

    /**
     * @param string $tableName
     * @param InputInterface $input
     * @return array
     */
    private function prepareSqlForOneTable(string $tableName, InputInterface $input): array
    {
        $sql = [];
        if ($input->getOption('drop-version')) { //explicit drop!
            $sql = $this->generateService->dropVersionTableAndTriggersInOriginTable($tableName);
        } elseif ($input->getOption('drop-trigger')) { // explicit drop!
            $sql = $this->generateService->dropTriggers($tableName);
        } else { // default
            if (in_array($tableName, $this->jobs['DropTrigger'])) {
                foreach ($this->generateService->dropTriggers($tableName) as $query) {
                    $sql[] = $query;
                }
            }
            if (in_array($tableName, $this->jobs['CreateVersion'])) {
                foreach ($this->generateService->createVersionAndTriggers($tableName) as $query) {
                    $sql[] = $query;
                }
            /**
             * The `CreateVersion` job will create the version table and also
             * the triggers. That's why the the `CreateTrigger` job is never
             * available, if a `CreateVersion` job is set: hence, the `else if`.
             */
            } else if (in_array($tableName, $this->jobs['CreateTrigger'])) {
                foreach ($this->generateService->createTriggers($tableName) as $query) {
                    $sql[] = $query;
                }
            }
        }
        return $sql;
    }

    /** print report to stdout
     *
     * @param SymfonyStyle $io
     * @return int
     */
    private function printReport(SymfonyStyle $io): int
    {
        $errors = [];
        $warnings = [];
        foreach ($this->jobService->getReport() as $message) {
            if (preg_match('/^ERROR/', $message)) {
                $errors[] = $message;
            } elseif (preg_match('/^WARNING/', $message)) {
                $warnings[] = $message;
            } else {
                $io->writeln($message);
            }
        }
        if (0 < count($warnings)) {
            $io->warning(implode(PHP_EOL, $warnings));
        }
        if (0 < count($errors)) {
            $io->error(implode(PHP_EOL, $errors));
        }
        return 0;
    }

    /** print sql to stdout
     *
     * @param SymfonyStyle $io
     * @return int
     */
    private function printSql(SymfonyStyle $io): int
    {
        foreach ($this->sql as $sql) {
            $io->writeln($sql);
        }
        return 0;
    }

    /**
     * @param SymfonyStyle $io
     * @param InputInterface $input
     */
    private function printStatistic(SymfonyStyle $io, InputInterface $input): void
    {
        $countTables = count($this->jobService->getTableNames());
        $countDropVersions = count($this->jobs['DropVersion']);
        $countDropTriggers = count($this->jobs['DropTrigger']);
        $countCreateVersions = count($this->jobs['CreateVersion']);
        $countCreateTriggers = count($this->jobs['CreateTrigger']);
        $io->writeln("Handling $countTables tables:");
        if (
            $input->getOption('drop-version')
            || $input->getOption('drop-trigger')
        ) {
            $io->writeln("Drop Versions $countDropVersions");
            $io->writeln("Drop Triggers $countDropTriggers");
            return;
        }
        $io->writeln("Drop Versions $countDropVersions");
        $io->writeln("Drop Triggers $countDropTriggers");
        $io->writeln("Create Version $countCreateVersions");
        $io->writeln("Create Triggers $countCreateTriggers");
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Doctrine\DBAL\ConnectionException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $tableName = $input->getArgument('tableName');
        try {
            $this->setJobs($tableName);
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }
        $this->sql = [];
        if (null !== $tableName) {
            $this->jobService->addTableName($tableName);
            $sql = $this->prepareSqlForOneTable($tableName, $input);
            foreach ($sql as $item) {
                $this->sql[] = $item;
            }
        } else {
            $this->prepareSqlForAllTable($input);
        }
        $io->newLine(1);
        $this->printStatistic($io, $input);
        $io->newLine(1);
        if ($input->getOption('summary')) {
            return $this->printReport($io);
        }
        if ($input->getOption('sql')) {
            return $this->printSql($io);
        }
        $io->newLine(2);
        if ($this->jobService->hasError()) {
            $this->printReport($io);
            $io->newLine(2);
            $io->note('Operation canceled');
            return Command::FAILURE;
        }
        $this->executeService->execute($this->sql);
        if ($this->jobService->hasWarning()) {
            $this->printReport($io);
        }
        $io->success('Operation success');
        return Command::SUCCESS;
    }
}
