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

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use NetBrothers\VersionBundle\Services\DatabaseConnectionProvider;
use NetBrothers\VersionBundle\Services\GenerateService;
use NetBrothers\VersionBundle\Services\JobService;
use NetBrothers\VersionBundle\Services\Report\ConsoleReportRenderer;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaBuilder;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaSyncer;
use NetBrothers\VersionBundle\Services\Sql\CreateTrigger;
use NetBrothers\VersionBundle\Services\Sql\DropTrigger;
use NetBrothers\VersionBundle\Services\Sql\ExecuteService;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;
use NetBrothers\VersionBundle\Services\Sql\SqlScriptFormatter;
use NetBrothers\VersionBundle\Services\Sql\VersionTable;
use NetBrothers\VersionBundle\Services\VersionConfigService;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: self::COMMAND_NAME,
    description: 'Create, synchronize, and manage MariaDB/MySQL version tables and triggers (standalone mode).',
)]
final class MakeVersionStandaloneCommand extends Command
{
    public const string COMMAND_NAME = 'netbrothers:version-standalone';

    private ?EntityManagerInterface $entityManager = null;

    protected function configure(): void
    {
        $this
            ->addArgument('tableName', InputArgument::OPTIONAL, 'Process only this origin table')
            ->addOption('drop-version', null, InputOption::VALUE_NONE, 'Drop versioning triggers and `_version` tables')
            ->addOption('drop-trigger', null, InputOption::VALUE_NONE, 'Drop versioning triggers only (keep `_version` tables)')
            ->addOption('sql', null, InputOption::VALUE_NONE, 'Output executable SQL statements to stdout without modifying the database')
            ->addOption('summary', null, InputOption::VALUE_NONE, 'Display the planned table changes and statistics without executing SQL')
            ->addOption('container-file', 'c', InputOption::VALUE_REQUIRED, 'Path to a PHP file returning a PSR-11 container with EntityManagerInterface')
            ->addOption('ignore-table', 'i', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Table name to ignore (repeatable)')
            ->addOption('exclude-column', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Non-PK column name to exclude from versioning (repeatable)')
            ->setHelp(<<<'HELP'
The <info>netbrothers-version</info> standalone CLI manages <comment>_version</comment> tables and versioning triggers for MariaDB/MySQL tables containing a <comment>version</comment> column using a PSR-11 container file.

<info>Recommended Migration Workflow:</info>
  1. Apply your Doctrine migrations (origin tables only).
  2. Synchronize version tables and recreate triggers:
     <comment>vendor/bin/netbrothers-version --container-file=config/container.php</comment>

  If a migration executes <comment>INSERT</comment> or <comment>UPDATE</comment> statements after altering a versioned table,
  drop triggers first with <comment>--drop-trigger</comment>, run the migration, then run the sync command.

<info>Examples:</info>
  <comment>vendor/bin/netbrothers-version -c config/container.php</comment>
    Synchronize all tables and recreate triggers

  <comment>vendor/bin/netbrothers-version -c config/container.php protocol</comment>
    Synchronize only the "protocol" table

  <comment>vendor/bin/netbrothers-version -c config/container.php --summary</comment>
    Dry-run: show planned table changes, column diffs, and counters

  <comment>vendor/bin/netbrothers-version -c config/container.php --sql > version_sync.sql</comment>
    Dry-run: export clean executable SQL script

  <comment>vendor/bin/netbrothers-version -c config/container.php -i audit_log -x cached_blob</comment>
    Ignore specific tables or exclude non-PK columns from versioning

  <comment>vendor/bin/netbrothers-version -c config/container.php --drop-trigger</comment>
    Drop triggers only (preserve version history)

  <comment>vendor/bin/netbrothers-version -c config/container.php --drop-version</comment>
    Drop triggers and all `_version` tables
HELP);
    }

    /**
     * @throws Exception
     * @throws \Doctrine\DBAL\Exception
     * @throws ExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->initEntityManager($input);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $config = [
            'ignore_tables' => $input->getOption('ignore-table'),
            'ignore_columns' => $input->getOption('exclude-column'),
        ];

        $configService = new VersionConfigService($config);
        $connectionProvider = new DatabaseConnectionProvider($this->entityManager);
        $identifierHelper = new IdentifierHelper();
        $schemaBuilder = new VersionTableSchemaBuilder($identifierHelper);
        $schemaSyncer = new VersionTableSchemaSyncer($schemaBuilder, $configService);
        $versionTable = new VersionTable($schemaBuilder, $configService);
        $dropTrigger = new DropTrigger($identifierHelper);
        $createTrigger = new CreateTrigger($identifierHelper, $schemaBuilder, $configService);
        $generateService = new GenerateService(
            $connectionProvider,
            $versionTable,
            $dropTrigger,
            $createTrigger,
            $identifierHelper,
        );
        $jobService = new JobService($connectionProvider, $schemaSyncer, $configService, $identifierHelper);
        $executeService = new ExecuteService($connectionProvider);
        $reportRenderer = new ConsoleReportRenderer();
        $sqlScriptFormatter = new SqlScriptFormatter();

        $symfonyCommand = new MakeVersionCommand(
            $connectionProvider,
            $jobService,
            $generateService,
            $executeService,
            $reportRenderer,
            $sqlScriptFormatter,
        );

        $forwardedParameters = [];
        $tableName = $input->getArgument('tableName');
        if (null !== $tableName) {
            $forwardedParameters['tableName'] = $tableName;
        }
        foreach (['drop-version', 'drop-trigger', 'sql', 'summary'] as $flag) {
            if ($input->getOption($flag)) {
                $forwardedParameters['--' . $flag] = true;
            }
        }

        $forwardedInput = new ArrayInput($forwardedParameters);
        $forwardedInput->setInteractive($input->isInteractive());

        return $symfonyCommand->run($forwardedInput, $output);
    }

    /**
     * @throws Exception|NotFoundExceptionInterface|ContainerExceptionInterface
     */
    private function initEntityManager(InputInterface $input): void
    {
        $containerFile = $input->getOption('container-file');
        if (empty($containerFile)) {
            throw new Exception('No valid container file given. Please use the --container-file= option.');
        }
        if (!is_readable($containerFile)) {
            throw new Exception(sprintf('The container file %s is not readable.', $containerFile));
        }
        $container = require $containerFile;
        if (!($container instanceof ContainerInterface)) {
            throw new Exception(sprintf('The container file %s does not yield a PSR-11 compatible container.', $containerFile));
        }
        $this->entityManager = $container->get(EntityManagerInterface::class);
        if (!($this->entityManager instanceof EntityManagerInterface)) {
            throw new Exception(sprintf('No `%s` is registered with the container.', EntityManagerInterface::class));
        }
    }
}
