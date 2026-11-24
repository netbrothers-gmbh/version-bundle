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

use NetBrothers\VersionBundle\Services\Sql\Definitions;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;
use Symfony\Component\Console\Command\Command;

final class VersionSyncIntegrationTest extends AbstractDatabaseIntegrationTestCase
{
    public function testFreshDatabaseCreatesVersionTableAndTriggersTrackRevisions(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE protocol (
                id INT AUTO_INCREMENT NOT NULL,
                version INT DEFAULT 1 NULL,
                title VARCHAR(100) NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $tester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        // Verify 4 triggers created
        $triggers = $this->fetchTriggerNames();
        self::assertSame([
            'protocol_AFTER_INSERT_INSERT_VERSION',
            'protocol_AFTER_UPDATE_INSERT_VERSION',
            'protocol_BEFORE_INSERT_SET_VERSION',
            'protocol_BEFORE_UPDATE_SET_VERSION',
        ], $triggers);

        // INSERT -> version = 1 + snapshot 1 in protocol_version
        $this->connection->executeStatement("INSERT INTO protocol (title) VALUES ('Initial')");
        $id = (int) $this->connection->lastInsertId();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT version FROM protocol WHERE id = ?', [$id]));

        // UPDATE -> version = 2 + snapshot 2 in protocol_version
        $this->connection->executeStatement("UPDATE protocol SET title = 'Updated' WHERE id = ?", [$id]);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT version FROM protocol WHERE id = ?', [$id]));

        $revisions = $this->connection->fetchAllAssociative(
            'SELECT id, version, title FROM protocol_version WHERE id = ? ORDER BY version ASC',
            [$id],
        );
        self::assertCount(2, $revisions);
        self::assertSame('Initial', $revisions[0]['title']);
        self::assertSame(1, (int) $revisions[0]['version']);
        self::assertSame('Updated', $revisions[1]['title']);
        self::assertSame(2, (int) $revisions[1]['version']);

        // DELETE -> cascades to protocol_version
        $this->connection->executeStatement('DELETE FROM protocol WHERE id = ?', [$id]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM protocol_version WHERE id = ?', [$id]));
    }

    public function testExistingRowsWithNullVersionAreBackfilledAndUpdatedWithoutPkCollision(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE vehicle (
                id INT AUTO_INCREMENT NOT NULL,
                version INT DEFAULT 1 NULL,
                plate VARCHAR(32) NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $this->connection->executeStatement("INSERT INTO vehicle (version, plate) VALUES (NULL, 'HH-AB-1'), (3, 'HH-CD-2')");

        $tester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        // NULL version normalized to 1 on origin and backfilled into vehicle_version
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT version FROM vehicle WHERE plate = 'HH-AB-1'"));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM vehicle_version'));

        // Updating the previously-NULL row must increment to 2 without duplicate PK error
        $this->connection->executeStatement("UPDATE vehicle SET plate = 'HH-AB-1-NEW' WHERE plate = 'HH-AB-1'");
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT version FROM vehicle WHERE plate = 'HH-AB-1-NEW'"));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM vehicle_version'));
    }

    public function testAutomaticSchemaSyncAddModifyDropStrictModeAndIdempotency(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE protocol (
                id INT AUTO_INCREMENT NOT NULL,
                version INT DEFAULT 1 NOT NULL,
                title VARCHAR(50) NOT NULL,
                amount DECIMAL(10, 2) DEFAULT NULL,
                optional_note VARCHAR(100) DEFAULT NULL,
                obsolete_col VARCHAR(50) DEFAULT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
        SQL);

        $tester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        // Populate historical rows where optional_note is NULL
        $this->connection->executeStatement(
            "INSERT INTO protocol (title, amount, optional_note, obsolete_col) VALUES ('Rev 1', 12.50, NULL, 'old')",
        );
        $id = (int) $this->connection->lastInsertId();

        // Simulate Doctrine migration on origin table ONLY:
        // 1. ADD NOT NULL with default, ADD DATETIME NOT NULL without default, ADD JSON NOT NULL, ADD TEXT NOT NULL
        // 2. MODIFY VARCHAR(50) -> VARCHAR(255), DECIMAL(10,2) -> DECIMAL(12,4)
        // 3. Backfill origin optional_note and tighten NULL -> NOT NULL (historical _version row still has NULL!)
        // 4. DROP obsolete_col
        $this->connection->executeStatement("UPDATE protocol SET optional_note = 'filled' WHERE optional_note IS NULL");
        $this->connection->executeStatement(<<<'SQL'
            ALTER TABLE protocol
                ADD client_revision INT DEFAULT 0 NOT NULL,
                ADD synced_at DATETIME NOT NULL DEFAULT '2026-01-01 00:00:00',
                ADD payload JSON NOT NULL,
                ADD notes LONGTEXT NOT NULL,
                MODIFY title VARCHAR(255) NOT NULL,
                MODIFY amount DECIMAL(12, 4) DEFAULT NULL,
                MODIFY optional_note VARCHAR(100) NOT NULL,
                DROP COLUMN obsolete_col
        SQL);
        // Remove the temporary default on synced_at so origin truly has DATETIME NOT NULL without default
        $this->connection->executeStatement('ALTER TABLE protocol ALTER COLUMN synced_at DROP DEFAULT');

        // Run netbrothers:version -> must sync protocol_version cleanly without strict-mode or NULL errors
        $syncTester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $syncTester->execute([]));
        self::assertStringContainsString('UPDATE', $syncTester->getDisplay());

        // Verify triggers work after schema sync with all new/modified columns
        $this->connection->executeStatement(
            "UPDATE protocol SET title = ?, amount = ?, client_revision = ?, synced_at = ?, payload = ?, notes = ? WHERE id = ?",
            [str_repeat('x', 200), '12345678.1234', 5, '2026-11-24 12:00:00', '{"ok":true}', 'long text body', $id],
        );

        $latest = $this->connection->fetchAssociative(
            'SELECT * FROM protocol_version WHERE id = ? ORDER BY version DESC LIMIT 1',
            [$id],
        );
        self::assertIsArray($latest);
        self::assertSame(5, (int) $latest['client_revision']);
        self::assertSame(str_repeat('x', 200), $latest['title']);
        self::assertArrayNotHasKey('obsolete_col', $latest);

        // Idempotency check: second run with --sql must produce ZERO ALTER TABLE statements!
        $sqlTester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $sqlTester->execute(['--sql' => true]));
        $sqlOutput = $sqlTester->getDisplay();
        self::assertStringNotContainsString('ALTER TABLE', $sqlOutput);
        self::assertStringContainsString('DELIMITER $$', $sqlOutput);
    }

    public function testLegacyV4BevorTriggerIsAutomaticallyCleanedUp(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE legacy_doc (
                id INT AUTO_INCREMENT NOT NULL,
                version INT DEFAULT 1 NOT NULL,
                name VARCHAR(64) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB
        SQL);

        $db = $this->connection->getDatabase();
        self::assertNotNull($db);
        $this->connection->executeStatement(sprintf(
            'CREATE DEFINER = CURRENT_USER TRIGGER `%s`.`legacy_doc_%s` BEFORE INSERT ON `legacy_doc` FOR EACH ROW BEGIN SET NEW.version = 1; END',
            $db,
            Definitions::LEGACY_TRIGGER_NAME_BEFORE_INSERT_SET_VERSION,
        ));

        self::assertContains('legacy_doc_BEVOR_INSERT_SET_VERSION', $this->fetchTriggerNames());

        $tester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $triggers = $this->fetchTriggerNames();
        self::assertNotContains('legacy_doc_BEVOR_INSERT_SET_VERSION', $triggers);
        self::assertContains('legacy_doc_BEFORE_INSERT_SET_VERSION', $triggers);
    }

    public function testLongTableNamesAndReservedKeywordIdentifiersWork(): void
    {
        $longTable = 'very_long_enterprise_vehicle_transfer_protocol_history_record'; // 61 chars

        $this->connection->executeStatement(sprintf(<<<'SQL'
            CREATE TABLE `%s` (
                id INT AUTO_INCREMENT NOT NULL,
                version INT DEFAULT 1 NOT NULL,
                `group` VARCHAR(64) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB
        SQL, $longTable));

        $this->connection->executeStatement(<<<'SQL'
            CREATE TABLE `order` (
                id INT AUTO_INCREMENT NOT NULL,
                version INT DEFAULT 1 NOT NULL,
                `group` VARCHAR(64) NOT NULL,
                `select` INT DEFAULT 0 NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE = InnoDB
        SQL);

        $tester = $this->createCommandTester();
        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $idHelper = new IdentifierHelper();
        $longVersionTable = $idHelper->buildVersionTableName($longTable);

        $this->connection->executeStatement(sprintf("INSERT INTO `%s` (`group`) VALUES ('alpha')", $longTable));
        self::assertSame(1, (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM `%s`', $longVersionTable)));

        $this->connection->executeStatement("INSERT INTO `order` (`group`, `select`) VALUES ('beta', 7)");
        $this->connection->executeStatement("UPDATE `order` SET `select` = 8 WHERE `group` = 'beta'");
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM `order_version`'));
    }

    public function testSingleTableSummaryDropFlagsIgnoreConfigAndFilterRestoration(): void
    {
        $this->connection->executeStatement('CREATE TABLE t_one (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, name VARCHAR(50) NOT NULL, secret VARCHAR(50) DEFAULT NULL, PRIMARY KEY (id)) ENGINE = InnoDB');
        $this->connection->executeStatement('CREATE TABLE t_two (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, name VARCHAR(50) NOT NULL, PRIMARY KEY (id)) ENGINE = InnoDB');
        $this->connection->executeStatement('CREATE TABLE t_ignored (id INT AUTO_INCREMENT NOT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id)) ENGINE = InnoDB');

        // Set a custom SchemaAssetsFilter on the connection and ensure it is restored!
        $customFilter = static fn (mixed $assetName): bool => $assetName !== 'excluded_by_app';
        $this->connection->getConfiguration()->setSchemaAssetsFilter($customFilter);

        // --summary dry-run must not create tables
        $tester = $this->createCommandTester(['ignore_tables' => ['t_ignored'], 'ignore_columns' => ['secret']]);
        self::assertSame(Command::SUCCESS, $tester->execute(['--summary' => true]));
        self::assertStringContainsString('[DRY-RUN]', $tester->getDisplay());
        self::assertSame($customFilter, $this->connection->getConfiguration()->getSchemaAssetsFilter());
        self::assertFalse($this->connection->createSchemaManager()->tableExists('t_one_version'));

        // Single-table execution processes ONLY t_one (and ignores secret column)
        self::assertSame(Command::SUCCESS, $tester->execute(['tableName' => 't_one']));
        self::assertTrue($this->connection->createSchemaManager()->tableExists('t_one_version'));
        self::assertFalse($this->connection->createSchemaManager()->tableExists('t_two_version'));
        self::assertFalse($this->connection->createSchemaManager()->tableExists('t_ignored_version'));
        self::assertFalse($this->connection->createSchemaManager()->introspectTableByUnquotedName('t_one_version')->hasColumn('secret'));
        self::assertSame($customFilter, $this->connection->getConfiguration()->getSchemaAssetsFilter());

        // --drop-trigger removes triggers while preserving t_one_version
        self::assertSame(Command::SUCCESS, $tester->execute(['tableName' => 't_one', '--drop-trigger' => true]));
        self::assertSame([], $this->fetchTriggerNames());
        self::assertTrue($this->connection->createSchemaManager()->tableExists('t_one_version'));

        // --drop-version removes t_one_version
        self::assertSame(Command::SUCCESS, $tester->execute(['tableName' => 't_one', '--drop-version' => true]));
        self::assertFalse($this->connection->createSchemaManager()->tableExists('t_one_version'));
    }
}
