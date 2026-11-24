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

namespace NetBrothers\VersionBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use NetBrothers\VersionBundle\Command\MakeVersionCommand;
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
use Symfony\Component\Console\Tester\CommandTester;

abstract class AbstractDatabaseIntegrationTestCase extends TestCase
{
    protected Connection $connection;
    protected EntityManager $entityManager;
    protected string $databaseUrl;

    protected function setUp(): void
    {
        $url = getenv('TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('Set TEST_DATABASE_URL (e.g. pdo-mysql://root:root@127.0.0.1:3306/version_bundle_test) to run MariaDB/MySQL integration tests.');
        }

        $this->databaseUrl = $url;
        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'pdo-mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']);
        $params = $parser->parse($url);

        $this->connection = DriverManager::getConnection($params);
        $ormConfig = ORMSetup::createAttributeMetadataConfig([], true);
        $ormConfig->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager($this->connection, $ormConfig);

        $this->resetDatabase();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->resetDatabase();
            $this->connection->close();
        }
    }

    protected function resetDatabase(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $databaseName = $this->connection->getDatabase();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            if ($databaseName !== null) {
                $triggers = $this->connection->fetchFirstColumn(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
                    [$databaseName],
                );
                foreach ($triggers as $triggerName) {
                    if (is_string($triggerName)) {
                        $this->connection->executeStatement(sprintf(
                            'DROP TRIGGER IF EXISTS %s.%s',
                            $platform->quoteSingleIdentifier($databaseName),
                            $platform->quoteSingleIdentifier($triggerName),
                        ));
                    }
                }
            }

            $tables = $this->connection->fetchFirstColumn('SHOW TABLES');
            foreach ($tables as $tableName) {
                if (is_string($tableName)) {
                    $this->connection->executeStatement(sprintf(
                        'DROP TABLE IF EXISTS %s',
                        $platform->quoteSingleIdentifier($tableName),
                    ));
                }
            }
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @param array{ignore_tables?: string[], ignore_columns?: string[]} $config
     */
    protected function createCommandTester(array $config = []): CommandTester
    {
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

        $command = new MakeVersionCommand(
            $connectionProvider,
            $jobService,
            $generateService,
            $executeService,
            new ConsoleReportRenderer(),
            new SqlScriptFormatter(),
        );

        return new CommandTester($command);
    }

    /**
     * @return string[]
     */
    protected function fetchTriggerNames(): array
    {
        $databaseName = $this->connection->getDatabase();
        self::assertNotNull($databaseName);

        $rows = $this->connection->fetchFirstColumn(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME',
            [$databaseName],
        );

        return array_values(array_filter($rows, is_string(...)));
    }
}
