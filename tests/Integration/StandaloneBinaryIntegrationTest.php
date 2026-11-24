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

final class StandaloneBinaryIntegrationTest extends AbstractDatabaseIntegrationTestCase
{
    public function testStandaloneBinaryRunsEndToEndWithContainerFile(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE standalone_item (
                id INT AUTO_INCREMENT NOT NULL,
                version INT DEFAULT 1 NOT NULL,
                label VARCHAR(100) NOT NULL,
                skip_me VARCHAR(50) DEFAULT NULL,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB
        SQL);

        $tmpFile = tempnam(sys_get_temp_dir(), 'nb_version_container_');
        self::assertIsString($tmpFile);

        $autoloadPath = realpath(__DIR__ . '/../../vendor/autoload.php');
        self::assertIsString($autoloadPath);

        $containerScript = sprintf(<<<'PHP'
<?php
declare(strict_types=1);

require_once %s;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

$parser = new DsnParser(['mysql' => 'pdo_mysql', 'pdo-mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']);
$connection = DriverManager::getConnection($parser->parse(%s));
$em = new EntityManager($connection, ORMSetup::createAttributeMetadataConfig([], true));

return new class($em) implements ContainerInterface {
    public function __construct(private readonly EntityManagerInterface $em) {}
    public function get(string $id): mixed {
        if ($id === EntityManagerInterface::class) {
            return $this->em;
        }
        throw new class("Service not found: $id") extends \RuntimeException implements NotFoundExceptionInterface {};
    }
    public function has(string $id): bool {
        return $id === EntityManagerInterface::class;
    }
};
PHP,
            var_export($autoloadPath, true),
            var_export($this->databaseUrl, true),
        );

        file_put_contents($tmpFile, $containerScript);

        try {
            $binary = realpath(__DIR__ . '/../../bin/netbrothers-version');
            self::assertIsString($binary);

            $cmd = sprintf(
                '%s %s --container-file=%s --exclude-column=skip_me',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($binary),
                escapeshellarg($tmpFile),
            );

            $outputLines = [];
            $exitCode = 0;
            exec($cmd . ' 2>&1', $outputLines, $exitCode);
            $output = implode("\n", $outputLines);

            self::assertSame(0, $exitCode, "Standalone binary failed:\n" . $output);
            self::assertStringContainsString('Operation completed', $output);

            self::assertTrue($this->connection->createSchemaManager()->tableExists('standalone_item_version'));
            self::assertFalse(
                $this->connection->createSchemaManager()->introspectTableByUnquotedName('standalone_item_version')->hasColumn('skip_me'),
            );

            $this->connection->executeStatement("INSERT INTO standalone_item (label, skip_me) VALUES ('first', 'ignored')");
            $this->connection->executeStatement("UPDATE standalone_item SET label = 'second'");
            self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM standalone_item_version'));
        } finally {
            @unlink($tmpFile);
        }
    }
}
