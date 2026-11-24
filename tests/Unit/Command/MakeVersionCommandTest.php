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

namespace NetBrothers\VersionBundle\Tests\Unit\Command;

use NetBrothers\VersionBundle\Command\MakeVersionCommand;
use NetBrothers\VersionBundle\Command\MakeVersionStandaloneCommand;
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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeVersionCommandTest extends TestCase
{
    private function createCommand(): MakeVersionCommand
    {
        $configService = new VersionConfigService([]);
        $connectionProvider = new DatabaseConnectionProvider(null, null);
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

        return new MakeVersionCommand(
            $connectionProvider,
            $jobService,
            $generateService,
            $executeService,
            new ConsoleReportRenderer(),
            new SqlScriptFormatter(),
        );
    }

    public function testServiceGraphIsInstantiatedLazilyWithoutDatabaseConnection(): void
    {
        $command = $this->createCommand();
        self::assertSame('netbrothers:version', $command->getName());
        self::assertStringContainsString('Recommended Migration Workflow:', $command->getHelp());
        self::assertStringContainsString('--sql > version_sync.sql', $command->getHelp());
    }

    public function testRejectsMutuallyExclusiveDropFlags(): void
    {
        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([
            '--drop-trigger' => true,
            '--drop-version' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('cannot be used together', $tester->getDisplay());
    }

    public function testRejectsMutuallyExclusiveSqlAndSummaryFlags(): void
    {
        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([
            '--sql' => true,
            '--summary' => true,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('cannot be used together', $tester->getDisplay());
    }

    public function testStandaloneCommandFailsCleanlyWithoutContainerFile(): void
    {
        $command = new MakeVersionStandaloneCommand();
        self::assertStringContainsString('vendor/bin/netbrothers-version', $command->getHelp());

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--summary' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--container-file=', $tester->getDisplay());
    }

    public function testStandaloneCommandFailsCleanlyWithUnreadableContainerFile(): void
    {
        $tester = new CommandTester(new MakeVersionStandaloneCommand());
        $exitCode = $tester->execute([
            '--container-file' => '/nonexistent/path/container.php',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('not readable', $tester->getDisplay());
    }
}
