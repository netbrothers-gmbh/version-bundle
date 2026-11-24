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

namespace NetBrothers\VersionBundle\Tests\Unit\Services\Sql;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaBuilder;
use NetBrothers\VersionBundle\Services\Sql\CreateTrigger;
use NetBrothers\VersionBundle\Services\Sql\Definitions;
use NetBrothers\VersionBundle\Services\Sql\DropTrigger;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;
use NetBrothers\VersionBundle\Services\Sql\VersionTable;
use NetBrothers\VersionBundle\Services\VersionConfigService;
use PHPUnit\Framework\TestCase;

final class TriggerAndVersionTableSqlTest extends TestCase
{
    private MySQLPlatform $platform;
    private IdentifierHelper $identifierHelper;
    private VersionTableSchemaBuilder $schemaBuilder;

    protected function setUp(): void
    {
        $this->platform = new MySQLPlatform();
        $this->identifierHelper = new IdentifierHelper();
        $this->schemaBuilder = new VersionTableSchemaBuilder($this->identifierHelper);
    }

    public function testDropTriggerDropsAllFourV5TriggersAndLegacyV4Trigger(): void
    {
        $dropTrigger = new DropTrigger($this->identifierHelper);
        $sql = $dropTrigger->getSql('app_db', 'order', $this->platform);

        self::assertCount(5, $sql);
        self::assertSame('DROP TRIGGER IF EXISTS `app_db`.`order_' . Definitions::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION . '`', $sql[0]);
        self::assertSame('DROP TRIGGER IF EXISTS `app_db`.`order_' . Definitions::LEGACY_TRIGGER_NAME_BEFORE_INSERT_SET_VERSION . '`', $sql[1]);
        self::assertSame('DROP TRIGGER IF EXISTS `app_db`.`order_' . Definitions::TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION . '`', $sql[2]);
        self::assertSame('DROP TRIGGER IF EXISTS `app_db`.`order_' . Definitions::TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION . '`', $sql[3]);
        self::assertSame('DROP TRIGGER IF EXISTS `app_db`.`order_' . Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION . '`', $sql[4]);
    }

    public function testCreateTriggerUsesCoalesceQuotesReservedWordsAndRespectsIgnoreColumns(): void
    {
        $config = new VersionConfigService(['ignore_columns' => ['ignored_col', 'id', 'version']]);
        $createTrigger = new CreateTrigger($this->identifierHelper, $this->schemaBuilder, $config);

        $origin = Table::editor()
            ->setUnquotedName('order')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('version')->setTypeName('integer')->setNotnull(false)->create())
            ->addColumn(Column::editor()->setUnquotedName('group')->setTypeName('string')->setLength(64)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('ignored_col')->setTypeName('string')->setLength(64)->setNotnull(false)->create())
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();

        $sql = $createTrigger->getSql('app_db', $origin, $this->platform);

        self::assertCount(4, $sql);
        self::assertStringContainsString('`app_db`.`order_BEFORE_INSERT_SET_VERSION` BEFORE INSERT ON `order`', $sql[0]);
        self::assertStringContainsString('SET NEW.`version` = 1;', $sql[0]);

        self::assertStringContainsString('`app_db`.`order_AFTER_INSERT_INSERT_VERSION` AFTER INSERT ON `order`', $sql[1]);
        self::assertStringContainsString('INSERT INTO `app_db`.`order_version` (`id`, `version`, `group`) VALUES (NEW.`id`, NEW.`version`, NEW.`group`);', $sql[1]);
        self::assertStringNotContainsString('ignored_col', $sql[1]);

        self::assertStringContainsString('`app_db`.`order_BEFORE_UPDATE_SET_VERSION` BEFORE UPDATE ON `order`', $sql[2]);
        self::assertStringContainsString('SET NEW.`version` = COALESCE(OLD.`version`, 0) + 1;', $sql[2]);

        self::assertStringContainsString('`app_db`.`order_AFTER_UPDATE_INSERT_VERSION` AFTER UPDATE ON `order`', $sql[3]);
    }

    public function testVersionTableGetSqlCreateIncludesDdlNullNormalizationAndExplicitBackfill(): void
    {
        $config = new VersionConfigService(['ignore_columns' => ['ignored_col']]);
        $versionTable = new VersionTable($this->schemaBuilder, $config);

        $origin = Table::editor()
            ->setUnquotedName('order')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setAutoincrement(true)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('version')->setTypeName('integer')->setNotnull(false)->setDefaultValue(1)->create())
            ->addColumn(Column::editor()->setUnquotedName('group')->setTypeName('string')->setLength(64)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('ignored_col')->setTypeName('string')->setLength(64)->setNotnull(false)->create())
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();

        $sql = $versionTable->getSqlCreate($origin, $this->platform);
        $joined = implode("\n", $sql);

        self::assertStringContainsString('CREATE TABLE order_version', $joined);
        self::assertStringContainsString('PRIMARY KEY (id, version)', $joined);
        self::assertStringContainsString('ON DELETE CASCADE', $joined);
        self::assertStringContainsString('UPDATE `order` SET `version` = 1 WHERE `version` IS NULL', $joined);
        self::assertStringContainsString('INSERT INTO `order_version` (`id`, `version`, `group`) SELECT `id`, `version`, `group` FROM `order`', $joined);
        self::assertStringNotContainsString('ignored_col', $joined);

        self::assertSame('DROP TABLE IF EXISTS `order_version`', $versionTable->dropVersionTable('order_version', $this->platform));
    }
}
