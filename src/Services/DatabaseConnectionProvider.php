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

namespace NetBrothers\VersionBundle\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Lazily resolves the Doctrine DBAL Connection (optionally for a named EntityManager)
 * and performs schema introspection without permanently mutating SchemaAssetsFilter.
 */
final class DatabaseConnectionProvider
{
    private ?string $selectedEntityManagerName = null;
    private ?Connection $resolvedConnection = null;

    public function __construct(
        private readonly ?EntityManagerInterface $defaultEntityManager = null,
        private readonly ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function selectEntityManager(?string $entityManagerName): void
    {
        if ($this->selectedEntityManagerName !== $entityManagerName) {
            $this->selectedEntityManagerName = $entityManagerName;
            $this->resolvedConnection = null;
        }
    }

    /**
     * @throws Exception|\RuntimeException
     */
    public function getConnection(): Connection
    {
        if ($this->resolvedConnection !== null) {
            return $this->resolvedConnection;
        }

        $entityManager = $this->resolveEntityManager();
        $connection = $entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        if (!($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform)) {
            throw new \RuntimeException(sprintf('Unsupported database platform: %s', $platform::class));
        }

        $this->resolvedConnection = $connection;

        return $connection;
    }

    /**
     * @throws Exception|\RuntimeException
     */
    public function getPlatform(): AbstractPlatform
    {
        return $this->getConnection()->getDatabasePlatform();
    }

    /**
     * @throws Exception|\RuntimeException
     */
    public function getDatabaseName(): string
    {
        $databaseName = $this->getConnection()->getDatabase();
        if ($databaseName === null) {
            throw new \RuntimeException('Unable to determine the current database name from the Doctrine connection.');
        }

        return $databaseName;
    }

    /**
     * Introspects all tables in the database (ignoring any configured SchemaAssetsFilter
     * while restoring the previous filter afterwards).
     *
     * @return array<string, Table> Map of unquoted table name => Table
     * @throws Exception|\RuntimeException
     */
    public function introspectAllTables(): array
    {
        return $this->withUnfilteredSchemaManager(static function (AbstractSchemaManager $schemaManager): array {
            $tables = [];
            foreach ($schemaManager->introspectTables() as $table) {
                $tables[$table->getObjectName()->getUnqualifiedName()->getValue()] = $table;
            }

            return $tables;
        });
    }

    /**
     * @throws Exception|\RuntimeException
     */
    public function introspectTable(string $tableName): Table
    {
        return $this->withUnfilteredSchemaManager(
            static fn (AbstractSchemaManager $schemaManager): Table => $schemaManager->introspectTableByUnquotedName($tableName),
        );
    }

    /**
     * @throws Exception|\RuntimeException
     */
    public function tableExists(string $tableName): bool
    {
        return $this->withUnfilteredSchemaManager(
            static fn (AbstractSchemaManager $schemaManager): bool => $schemaManager->tableExists($tableName),
        );
    }

    private function resolveEntityManager(): EntityManagerInterface
    {
        if ($this->selectedEntityManagerName !== null) {
            if ($this->managerRegistry === null) {
                throw new \RuntimeException(
                    'The --em option requires Doctrine ManagerRegistry (available in Symfony bundle mode).',
                );
            }

            $manager = $this->managerRegistry->getManager($this->selectedEntityManagerName);
            if (!$manager instanceof EntityManagerInterface) {
                throw new \RuntimeException(sprintf(
                    'Object manager "%s" is not an instance of %s.',
                    $this->selectedEntityManagerName,
                    EntityManagerInterface::class,
                ));
            }

            return $manager;
        }

        if ($this->defaultEntityManager !== null) {
            return $this->defaultEntityManager;
        }

        if ($this->managerRegistry !== null) {
            $manager = $this->managerRegistry->getManager();
            if ($manager instanceof EntityManagerInterface) {
                return $manager;
            }
        }

        throw new \RuntimeException('No Doctrine EntityManagerInterface is available.');
    }

    /**
     * @template T
     * @param callable(AbstractSchemaManager<AbstractPlatform>): T $callback
     * @return T
     * @throws Exception|\RuntimeException
     */
    private function withUnfilteredSchemaManager(callable $callback): mixed
    {
        $connection = $this->getConnection();
        $configuration = $connection->getConfiguration();
        $previousFilter = $configuration->getSchemaAssetsFilter();
        $configuration->setSchemaAssetsFilter(static fn (): bool => true);

        try {
            return $callback($connection->createSchemaManager());
        } finally {
            $configuration->setSchemaAssetsFilter($previousFilter);
        }
    }
}
