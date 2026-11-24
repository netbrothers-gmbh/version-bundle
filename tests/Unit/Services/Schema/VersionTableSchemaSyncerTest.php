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

namespace NetBrothers\VersionBundle\Tests\Unit\Services\Schema;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaBuilder;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaSyncer;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;
use NetBrothers\VersionBundle\Services\VersionConfigService;
use PHPUnit\Framework\TestCase;

final class VersionTableSchemaSyncerTest extends TestCase
{
    private MySQLPlatform $platform;
    private Comparator $comparator;
    private VersionTableSchemaBuilder $builder;

    protected function setUp(): void
    {
        $this->platform = new MySQLPlatform();
        $this->comparator = new Comparator(
            $this->platform,
            new ComparatorConfig(
                detectRenamedColumns: false,
                detectRenamedIndexes: false,
                reportModifiedIndexes: false,
            ),
        );
        $this->builder = new VersionTableSchemaBuilder(new IdentifierHelper());
    }

    public function testPlansAddModifyDropAndProtectsIgnoredAndPkColumns(): void
    {
        $config = new VersionConfigService(['ignore_columns' => ['custom_audit_col', 'id', 'version']]);
        $syncer = new VersionTableSchemaSyncer($this->builder, $config);

        $origin = Table::editor()
            ->setUnquotedName('protocol')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setAutoincrement(true)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('version')->setTypeName('integer')->setNotnull(false)->setDefaultValue(1)->create())
            ->addColumn(Column::editor()->setUnquotedName('title')->setTypeName('string')->setLength(255)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('amount')->setTypeName('decimal')->setPrecision(12)->setScale(4)->setNotnull(false)->create())
            ->addColumn(Column::editor()->setUnquotedName('status')->setTypeName('string')->setLength(32)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('client_revision')->setTypeName('integer')->setDefaultValue(0)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('synced_at')->setTypeName('datetime_immutable')->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('payload')->setTypeName('json')->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('notes')->setTypeName('text')->setNotnull(true)->create())
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();

        $actualVersion = Table::editor()
            ->setUnquotedName('protocol_version')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('version')->setTypeName('integer')->setDefaultValue(1)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('title')->setTypeName('string')->setLength(50)->setNotnull(false)->create())
            ->addColumn(Column::editor()->setUnquotedName('amount')->setTypeName('decimal')->setPrecision(10)->setScale(2)->setNotnull(false)->create())
            ->addColumn(Column::editor()->setUnquotedName('status')->setTypeName('string')->setLength(32)->setNotnull(false)->create())
            ->addColumn(Column::editor()->setUnquotedName('obsolete_col')->setTypeName('string')->setLength(50)->setNotnull(false)->create())
            ->addColumn(Column::editor()->setUnquotedName('custom_audit_col')->setTypeName('string')->setLength(50)->setNotnull(false)->create())
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id', 'version')->create())
            ->addIndex(Index::editor()->setUnquotedName('legacy_idx_id')->setUnquotedColumnNames('id')->create())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('protocol_version_fk_c88697')
                    ->setUnquotedReferencingColumnNames('id')
                    ->setUnquotedReferencedTableName('protocol')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            )
            ->create();

        $plan = $syncer->planSync($origin, $actualVersion, $this->comparator, $this->platform);

        self::assertTrue($plan->hasChanges());
        self::assertSame('protocol', $plan->originTableName);
        self::assertSame('protocol_version', $plan->versionTableName);
        self::assertSame(['client_revision', 'synced_at', 'payload', 'notes'], $plan->addedColumns);
        self::assertSame(['title', 'amount'], $plan->modifiedColumns);
        self::assertSame(['obsolete_col'], $plan->droppedColumns);
        self::assertNotEmpty($plan->sql);

        $joinedSql = implode("\n", $plan->sql);
        self::assertStringNotContainsString('DROP INDEX', $joinedSql);
        self::assertStringNotContainsString('DROP FOREIGN KEY', $joinedSql);
        self::assertStringNotContainsString('NOT NULL', $joinedSql);
        self::assertStringNotContainsString('custom_audit_col', $joinedSql);
    }

    public function testPlanSyncIsIdempotentWhenTablesMatch(): void
    {
        $config = new VersionConfigService([]);
        $syncer = new VersionTableSchemaSyncer($this->builder, $config);

        $origin = Table::editor()
            ->setUnquotedName('vehicle')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setAutoincrement(true)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('version')->setTypeName('integer')->setNotnull(false)->setDefaultValue(1)->create())
            ->addColumn(Column::editor()->setUnquotedName('vin')->setTypeName('string')->setLength(32)->setNotnull(true)->create())
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();

        $expectedVersion = $this->builder->buildExpectedVersionTable($origin);
        $plan = $syncer->planSync($origin, $expectedVersion, $this->comparator, $this->platform);

        self::assertFalse($plan->hasChanges());
        self::assertSame([], $plan->addedColumns);
        self::assertSame([], $plan->modifiedColumns);
        self::assertSame([], $plan->droppedColumns);
        self::assertSame([], $plan->sql);
    }
}
