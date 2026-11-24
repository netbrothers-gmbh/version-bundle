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

use NetBrothers\VersionBundle\Services\Sql\Definitions;
use NetBrothers\VersionBundle\Services\Sql\IdentifierHelper;
use PHPUnit\Framework\TestCase;

final class IdentifierHelperTest extends TestCase
{
    private IdentifierHelper $helper;

    protected function setUp(): void
    {
        $this->helper = new IdentifierHelper();
    }

    public function testShortNamesAreReturnedUnchanged(): void
    {
        self::assertSame('protocol_version', $this->helper->buildVersionTableName('protocol'));
        self::assertSame(
            'protocol_BEFORE_INSERT_SET_VERSION',
            $this->helper->buildTriggerName('protocol', Definitions::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION),
        );
        self::assertSame(
            'protocol_version_fk_c88697',
            $this->helper->buildForeignKeyName('protocol'),
        );
    }

    public function testLongNamesAreDeterministicallyShortenedWithin64Characters(): void
    {
        $longA = 'very_long_enterprise_vehicle_transfer_protocol_history_record_a';
        $longB = 'very_long_enterprise_vehicle_transfer_protocol_history_record_b';

        $versionA = $this->helper->buildVersionTableName($longA);
        $versionB = $this->helper->buildVersionTableName($longB);

        self::assertLessThanOrEqual(Definitions::MAX_IDENTIFIER_LENGTH, strlen($versionA));
        self::assertLessThanOrEqual(Definitions::MAX_IDENTIFIER_LENGTH, strlen($versionB));
        self::assertStringEndsWith(Definitions::VERSION_TABLE_NAME_POSTFIX, $versionA);
        self::assertSame($versionA, $this->helper->buildVersionTableName($longA));
        self::assertNotSame($versionA, $versionB);

        $triggerA = $this->helper->buildTriggerName($longA, Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION);
        $triggerB = $this->helper->buildTriggerName($longB, Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION);

        self::assertLessThanOrEqual(Definitions::MAX_IDENTIFIER_LENGTH, strlen($triggerA));
        self::assertStringEndsWith('_' . Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION, $triggerA);
        self::assertNotSame($triggerA, $triggerB);

        $fkA = $this->helper->buildForeignKeyName($longA);
        $fkB = $this->helper->buildForeignKeyName($longB);

        self::assertLessThanOrEqual(Definitions::MAX_IDENTIFIER_LENGTH, strlen($fkA));
        self::assertStringEndsWith(Definitions::VERSION_TABLE_NAME_POSTFIX . Definitions::FK_POSTFIX, $fkA);
        self::assertNotSame($fkA, $fkB);
    }

    public function testBoundaryLengthTableNameFitsWithoutHashing(): void
    {
        // 36 chars + '_' + 27 chars ('AFTER_UPDATE_INSERT_VERSION') = 64 chars exact
        $tableName = str_repeat('a', 36);
        $triggerName = $this->helper->buildTriggerName($tableName, Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION);

        self::assertSame($tableName . '_' . Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION, $triggerName);
        self::assertSame(64, strlen($triggerName));

        // 37 chars exceeds 64 -> must be shortened
        $tableName37 = str_repeat('a', 37);
        $triggerName37 = $this->helper->buildTriggerName($tableName37, Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION);

        self::assertLessThanOrEqual(64, strlen($triggerName37));
        self::assertNotSame($tableName37 . '_' . Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION, $triggerName37);
    }
}
