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

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use NetBrothers\VersionBundle\Services\Schema\VersionTableSchemaBuilder;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;
use PHPUnit\Framework\TestCase;

final class VersionTableSchemaBuilderTest extends TestCase
{
    private VersionTableSchemaBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new VersionTableSchemaBuilder(new IdentifierHelper());
    }

    public function testBuildsExpectedVersionTableWithNullableNonPkColumnsAndCascadeFk(): void
    {
        $origin = Table::editor()
            ->setUnquotedName('protocol')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setAutoincrement(true)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('version')->setTypeName('integer')->setNotnull(false)->setDefaultValue(1)->create())
            ->addColumn(Column::editor()->setUnquotedName('title')->setTypeName('string')->setLength(120)->setNotnull(true)->setComment('Protocol title')->create())
            ->addColumn(Column::editor()->setUnquotedName('amount')->setTypeName('decimal')->setPrecision(10)->setScale(2)->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('created_at')->setTypeName('datetime_immutable')->setNotnull(true)->create())
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->addIndex(Index::editor()->setUnquotedName('uniq_title')->setUnquotedColumnNames('title')->setType(IndexType::UNIQUE)->create())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()
                    ->setUnquotedName('fk_origin_other')
                    ->setUnquotedReferencingColumnNames('title')
                    ->setUnquotedReferencedTableName('other')
                    ->setUnquotedReferencedColumnNames('name')
                    ->create(),
            )
            ->setOptions([
                'engine' => 'InnoDB',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'autoincrement' => 42,
            ])
            ->create();

        $versionTable = $this->builder->buildExpectedVersionTable($origin);

        self::assertSame('protocol_version', $versionTable->getObjectName()->getUnqualifiedName()->getValue());

        // PK columns: NOT NULL, no autoincrement
        self::assertTrue($versionTable->getColumn('id')->getNotnull());
        self::assertFalse($versionTable->getColumn('id')->getAutoincrement());
        self::assertTrue($versionTable->getColumn('version')->getNotnull());

        // Non-PK columns: forced nullable, attributes preserved
        self::assertFalse($versionTable->getColumn('title')->getNotnull());
        self::assertSame(120, $versionTable->getColumn('title')->getLength());
        self::assertSame('Protocol title', $versionTable->getColumn('title')->getComment());
        self::assertFalse($versionTable->getColumn('amount')->getNotnull());
        self::assertSame(10, $versionTable->getColumn('amount')->getPrecision());
        self::assertSame(2, $versionTable->getColumn('amount')->getScale());
        self::assertFalse($versionTable->getColumn('created_at')->getNotnull());

        // Composite PK (id, version)
        $pk = $versionTable->getPrimaryKeyConstraint();
        self::assertNotNull($pk);
        $pkCols = array_map(
            static fn ($name): string => $name->getIdentifier()->getValue(),
            $pk->getColumnNames(),
        );
        self::assertSame(['id', 'version'], $pkCols);

        // Origin unique index and origin FK excluded; only cascade FK to origin kept
        self::assertFalse($versionTable->hasIndex('uniq_title'));
        $fks = $versionTable->getForeignKeys();
        self::assertCount(1, $fks);
        $fk = reset($fks);
        self::assertNotFalse($fk);
        self::assertSame('protocol_version_fk_c88697', $fk->getObjectName()?->getIdentifier()->getValue());
        self::assertSame('CASCADE', $fk->getOnDeleteAction()->value);

        // Table options preserved without autoincrement
        $options = $versionTable->getOptions();
        self::assertSame('InnoDB', $options['engine']);
        self::assertSame('utf8mb4', $options['charset']);
        self::assertSame('utf8mb4_unicode_ci', $options['collation']);
        self::assertArrayNotHasKey('autoincrement', $options);
    }

    public function testIgnoresNonPkColumnsWhileProtectingPrimaryKeyColumns(): void
    {
        $origin = Table::editor()
            ->setUnquotedName('item')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('version')->setTypeName('integer')->setNotnull(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('cached_blob')->setTypeName('text')->setNotnull(false)->create())
            ->addColumn(Column::editor()->setUnquotedName('name')->setTypeName('string')->setLength(100)->setNotnull(true)->create())
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();

        $versionTable = $this->builder->buildExpectedVersionTable($origin, ['cached_blob', 'id', 'version']);

        self::assertTrue($versionTable->hasColumn('id'));
        self::assertTrue($versionTable->hasColumn('version'));
        self::assertTrue($versionTable->hasColumn('name'));
        self::assertFalse($versionTable->hasColumn('cached_blob'));
    }
}
