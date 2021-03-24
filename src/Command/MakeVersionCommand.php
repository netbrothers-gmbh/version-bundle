<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 19.03.21
 *
 */

namespace NetBrothers\VersionBundle\Command;


use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\ORM\EntityManagerInterface;
use NetBrothers\VersionBundle\Services\CompareService;
use NetBrothers\VersionBundle\Services\GenerateService;
use NetBrothers\VersionBundle\Services\JobService;
use NetBrothers\VersionBundle\Services\Sql\ExecuteService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MakeVersionCommand
 * @package NetBrothers\VersionBundle\Command
 */
class MakeVersionCommand extends Command
{
    protected static $defaultName = 'netbrothers:version';

    const HELP_TEXT=<<<EOF
This command creates versions of database tables. Records are copied via triggers.

All tables with a column named `version` (type INT/BIGINT) will get a version table called 
`[originTableName]_version` with same columns of the originTable. Every originTable gets trigger, 
which will increase the version column on insert/updates and saves a copy in the version table.

You can specify as argument a single table name. If you do not specify a single table name as argument, this
command will recognize every table - expected the configured tables to be ignored.

If you do not use any option, the default behaviour is to create version tables and corresponding triggers.
Also it will drop triggers on every table, which has no version table.             
            

Options:
========

create-trigger: Drop triggers, create necessary version tables, create triggers

drop-trigger:   Drop triggers

drop-version:   Drop triggers, drop version table(s) 

summary:        Overview about things to do

sql:            Overview of prepared SQL-Statements

dry-run:        Test SQL-Statements

EOF;

    /** @var JobService */
    private $jobService;

    /** @var GenerateService */
    private $generateService;

    /** @var array */
    private $sql = [];

    /** @var array  */
    private $jobs = [];

    /** @var ExecuteService */
    private $executeService;

    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var AbstractSchemaManager|null */
    private $schemaManager;

    /** @var bool */
    private $dryRun;

    /** configuration */
    protected function configure()
    {
        $this
            ->setDescription('Create version-tables and MySQl-Trigger')
            ->setHelp(self::HELP_TEXT)
            ->addArgument(
                'tableName',
                InputArgument::OPTIONAL,
                'Work only on this table')
            ->addOption(
                'create-trigger',
                '',
                InputOption::VALUE_NONE,
                'Drop and create new trigger')
            ->addOption(
                'drop-version',
                '',
                InputOption::VALUE_NONE,
                'Drop version table and trigger')
            ->addOption(
                'drop-trigger',
                '',
                InputOption::VALUE_NONE,
                'Drop trigger')
            ->addOption(
                'sql',
                '',
                InputOption::VALUE_NONE,
                'Print SQL-Statements to stdout'
            )
            ->addOption(
                'summary',
                '',
                InputOption::VALUE_NONE,
                'Print summary to stdout'
            )
            ->addOption(
                'dry-run',
                '',
                InputOption::VALUE_NONE,
                'Test SQL-Statements'
            )
        ;
    }

    /**
     * MakeVersionCommand constructor.
     * @param EntityManagerInterface $entityManager
     * @param array $ignoreTables
     * @param array $excludeColumnNames
     * @throws \Exception
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        array $ignoreTables = [],
        array $excludeColumnNames = []
    ){
        parent::__construct();
        $this->entityManager = $entityManager;
        $con = $this->entityManager->getConnection();
        $con->getConfiguration()->setSchemaAssetsFilter(null);
        $this->schemaManager = $this->entityManager->getConnection()->getSchemaManager();
        $compareService = new CompareService($this->schemaManager, $excludeColumnNames);
        $this->jobService = new JobService($this->schemaManager, $compareService, $ignoreTables);
        $this->generateService = new GenerateService($this->schemaManager, $con->getDatabase());
        $this->executeService = new ExecuteService($entityManager);
    }

    /**
     * @param string|null $tableName
     * @throws SchemaException
     */
    private function setJobs(string $tableName = null): void
    {
        $this->jobs = (null !== $tableName) ?
            $this->jobService->getJobForOneTable($tableName) : $this->jobService->getJobsForAllTables();
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
        if ( $input->getOption('drop-version')) { //explicit drop!
            $sql = $this->generateService->dropVersionTableAndTriggersInOriginTable($tableName);
        } elseif ($input->getOption('drop-trigger') ) { // explicit drop!
            $sql = $this->generateService->dropTriggers($tableName);
        } else { // default
            if (in_array($tableName, $this->jobs['DropTrigger'])) {
                foreach ($this->generateService->dropTriggers($tableName) as $query) {
                    $sql[] = $query;
                }
            }
            if (in_array($tableName, $this->jobs['CreateVersion'])) {
                foreach ($this->generateService->createVersion($tableName) as $query) {
                    $sql[] = $query;
                }
                return $sql;
            }
            if (in_array($tableName, $this->jobs['CreateTrigger'])) {
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
            if (preg_match("/^ERROR/", $message)) {
                $errors[] = $message;
            } elseif (preg_match("/^WARNING/", $message)) {
                $warnings[] = $message;
            } else {
                $io->writeln($message);
            }
        }
        if ( 0 < count($warnings)) {
            $io->warning(implode(PHP_EOL, $errors));
        }
        if ( 0 < count($errors)) {
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
        if ( $input->getOption('drop-version') || $input->getOption('drop-trigger')) {
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
     * @param SymfonyStyle $io
     */
    private function printDryRunInformation(InputInterface $input, SymfonyStyle $io): void
    {
        $this->dryRun = $input->getOption('dry-run');
        if (false === $this->dryRun) {
            if ($input->getOption('summary')) {
                $this->dryRun = true;
            } elseif ($input->getOption('sql')) {
                $this->dryRun = true;
            }
        }
        $defMsg =  "option dry-run is %s";
        $dRunStr = (true === $this->dryRun) ? 'enabled' : 'disabled';
        $io->note(sprintf($defMsg, $dRunStr));
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Doctrine\DBAL\ConnectionException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $tableName = $input->getArgument('tableName');
        try {
            $this->setJobs($tableName);
        } catch (\Exception $exception) {
            $io->error($exception->getMessage());
            return 1;
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
        $io->newLine(2);
        $this->printDryRunInformation($input, $io);
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
            $io->note("Operation canceled");
            return 1;
        }
        if (true === $this->dryRun) {
            $this->executeService->dryRun($this->sql);
        } else {
            $this->executeService->execute($this->sql);
        }
        if ($this->jobService->hasWarning()) {
            $this->printReport($io);
        }
        $io->success("Operation success");
        return 0;
    }
}