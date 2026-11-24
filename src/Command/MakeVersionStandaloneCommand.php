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
use NetBrothers\VersionBundle\Services\CompareService;
use NetBrothers\VersionBundle\Services\DatabaseConnectionProvider;
use NetBrothers\VersionBundle\Services\GenerateService;
use NetBrothers\VersionBundle\Services\JobService;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaBuilder;
use NetBrothers\VersionBundle\Services\Sql\CreateTrigger;
use NetBrothers\VersionBundle\Services\Sql\DropTrigger;
use NetBrothers\VersionBundle\Services\Sql\ExecuteService;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;
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
    description: 'Create version tables and triggers (standalone mode).'
)]
final class MakeVersionStandaloneCommand extends Command
{
    public const string COMMAND_NAME = 'netbrothers:version-standalone';

    private ?EntityManagerInterface $entityManager = null;

    protected function configure(): void
    {
        $this
            ->addArgument('tableName', InputArgument::OPTIONAL, 'Work only on this table')
            ->addOption('drop-version', null, InputOption::VALUE_NONE, 'Drop triggers and version tables')
            ->addOption('drop-trigger', null, InputOption::VALUE_NONE, 'Drop triggers only')
            ->addOption('sql', null, InputOption::VALUE_NONE, 'Print the SQL statements without doing anything')
            ->addOption('summary', null, InputOption::VALUE_NONE, 'Print a human readable summary of what the command would do')
            ->addOption('container-file', 'c', InputOption::VALUE_REQUIRED, 'Path to a PHP file which will return a PSR-11 compatible DI container.')
            ->addOption('ignore-table', 'i', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add as many `--ignore-table=` options as you need.')
            ->addOption('exclude-column', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add as many `--exclude-column=` options as you need.');
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

        // Manually build the service container
        $config = [
            'ignore_tables' => $input->getOption('ignore-table'),
            'ignore_columns' => $input->getOption('exclude-column'),
        ];

        $configService = new VersionConfigService($config);
        $connectionProvider = new DatabaseConnectionProvider($this->entityManager);
        $identifierHelper = new IdentifierHelper();
        $schemaBuilder = new VersionTableSchemaBuilder($identifierHelper);
        $compareService = new CompareService($configService, $schemaBuilder);
        $versionTable = new VersionTable($schemaBuilder);
        $dropTrigger = new DropTrigger($identifierHelper);
        $createTrigger = new CreateTrigger($identifierHelper);
        $generateService = new GenerateService(
            $connectionProvider,
            $versionTable,
            $dropTrigger,
            $createTrigger,
            $identifierHelper,
        );
        $jobService = new JobService($connectionProvider, $compareService, $configService, $identifierHelper);
        $executeService = new ExecuteService($connectionProvider);

        $symfonyCommand = new MakeVersionCommand($connectionProvider, $jobService, $generateService, $executeService);

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
