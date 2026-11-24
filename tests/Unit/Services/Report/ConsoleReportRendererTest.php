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

namespace NetBrothers\VersionBundle\Tests\Unit\Services\Report;

use NetBrothers\VersionBundle\Services\Report\ConsoleReportRenderer;
use NetBrothers\VersionBundle\Services\Report\ExecutionMode;
use NetBrothers\VersionBundle\Services\Report\TableJobEntry;
use NetBrothers\VersionBundle\Services\Report\TableStatus;
use NetBrothers\VersionBundle\Services\Schema\TableSyncPlan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ConsoleReportRendererTest extends TestCase
{
    private ConsoleReportRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ConsoleReportRenderer();
    }

    public function testRendersSyncReportWithColumnChangesWarningsAndCounters(): void
    {
        $entries = [
            new TableJobEntry('vehicle', TableStatus::CREATE, 'vehicle_version', null, 'Create vehicle_version, backfill rows, create triggers'),
            new TableJobEntry('protocol', TableStatus::UPDATE, 'protocol_version', new TableSyncPlan(
                'protocol',
                'protocol_version',
                ['client_revision'],
                ['title'],
                ['legacy_flag'],
                ['ALTER TABLE protocol_version ADD client_revision INT DEFAULT 0'],
            )),
            new TableJobEntry('driver', TableStatus::UP_TO_DATE, 'driver_version'),
            new TableJobEntry('old_version', TableStatus::ORPHANED, 'old_version'),
            new TableJobEntry('doctrine_migration_versions', TableStatus::IGNORED),
        ];

        $sql = [
            'DROP TRIGGER IF EXISTS `app`.`protocol_BEFORE_INSERT_SET_VERSION`',
            'ALTER TABLE protocol_version ADD client_revision INT DEFAULT 0',
            "CREATE DEFINER = CURRENT_USER TRIGGER `app`.`protocol_BEFORE_INSERT_SET_VERSION` BEFORE INSERT ON `protocol`\nFOR EACH ROW\nBEGIN\n    SET NEW.`version` = 1;\nEND",
        ];

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $this->renderer->render($io, $entries, ExecutionMode::SYNC, $sql, true);
        $rendered = $output->fetch();

        self::assertStringContainsString('[DRY-RUN]', $rendered);
        self::assertStringContainsString('+ ADD client_revision', $rendered);
        self::assertStringContainsString('~ MODIFY title', $rendered);
        self::assertStringContainsString('- DROP legacy_flag', $rendered);
        self::assertStringContainsString('permanently delete', $rendered);
        self::assertStringContainsString('legacy_flag', $rendered);
        self::assertStringContainsString('Orphaned version table "old_version"', $rendered);
        self::assertStringContainsString('+1 / ~1 / -1', $rendered);
    }

    public function testRendersDropTriggerAndDropVersionModesAccurately(): void
    {
        $entries = [
            new TableJobEntry('protocol', TableStatus::UP_TO_DATE, 'protocol_version'),
            new TableJobEntry('vehicle', TableStatus::CREATE, 'vehicle_version'),
            new TableJobEntry('old_version', TableStatus::ORPHANED, 'old_version'),
            new TableJobEntry('ignored_tbl', TableStatus::IGNORED),
        ];

        $dropTriggerOut = new BufferedOutput();
        $this->renderer->render(
            new SymfonyStyle(new ArrayInput([]), $dropTriggerOut),
            $entries,
            ExecutionMode::DROP_TRIGGER,
            ['DROP TRIGGER IF EXISTS `app`.`protocol_BEFORE_INSERT_SET_VERSION`'],
            false,
        );
        $triggerText = $dropTriggerOut->fetch();
        self::assertStringContainsString('Drop triggers only', $triggerText);
        self::assertStringContainsString('DROP TRIGGERS', $triggerText);
        self::assertStringContainsString('SKIPPED', $triggerText);

        $dropVersionOut = new BufferedOutput();
        $this->renderer->render(
            new SymfonyStyle(new ArrayInput([]), $dropVersionOut),
            $entries,
            ExecutionMode::DROP_VERSION,
            ['DROP TABLE IF EXISTS `protocol_version`', 'DROP TABLE IF EXISTS `old_version`'],
            false,
        );
        $versionText = $dropVersionOut->fetch();
        self::assertStringContainsString('Drop triggers & version tables', $versionText);
        self::assertStringContainsString('DROP VERSION', $versionText);
        self::assertStringContainsString('NO VERSION TABLE', $versionText);
    }
}
