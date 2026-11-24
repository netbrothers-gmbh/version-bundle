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

namespace NetBrothers\VersionBundle\Services\Report;

use NetBrothers\VersionBundle\Services\Schema\TableSyncPlan;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders structured table reports, data-loss warnings, and summary statistics to the CLI.
 */
final readonly class ConsoleReportRenderer
{
    /**
     * @param TableJobEntry[] $entries
     * @param string[] $sql
     */
    public function render(
        SymfonyStyle $io,
        array $entries,
        ExecutionMode $mode,
        array $sql,
        bool $isDryRun,
    ): void {
        $title = sprintf(
            'NetBrothers VersionBundle — %s%s',
            $mode->label(),
            $isDryRun ? ' [DRY-RUN]' : '',
        );
        $io->section($title);

        if ($entries === []) {
            $io->note('No tables found to process.');
        } else {
            $rows = [];
            foreach ($entries as $entry) {
                $rows[] = $this->formatTableRow($entry, $mode);
            }
            $io->table(['Table', 'Status', 'Details'], $rows);
        }

        $warnings = $this->collectWarnings($entries, $mode);
        if ($warnings !== []) {
            $io->warning($warnings);
        }

        $stats = $this->computeStatistics($entries, $mode, $sql);
        $io->definitionList(
            ['Tables inspected' => (string) $stats['tablesInspected']],
            ['Tables ignored' => (string) $stats['tablesIgnored']],
            ['Version tables created' => (string) $stats['versionTablesCreated']],
            ['Version tables updated' => (string) $stats['versionTablesUpdated']],
            ['Version tables dropped' => (string) $stats['versionTablesDropped']],
            ['Columns added / modified / dropped' => sprintf(
                '+%d / ~%d / -%d',
                $stats['columnsAdded'],
                $stats['columnsModified'],
                $stats['columnsDropped'],
            )],
            ['Trigger drop statements' => (string) $stats['triggersDropped']],
            ['Triggers created' => (string) $stats['triggersCreated']],
            ['Total SQL statements' => (string) $stats['totalSqlStatements']],
        );
    }

    /**
     * @return array{string, string, string}
     */
    private function formatTableRow(TableJobEntry $entry, ExecutionMode $mode): array
    {
        if ($mode === ExecutionMode::DROP_TRIGGER) {
            return match ($entry->status) {
                TableStatus::IGNORED => [$entry->tableName, '<fg=gray>IGNORED</>', 'Configured in ignore_tables'],
                TableStatus::ORPHANED => [$entry->tableName, '<fg=gray>SKIPPED</>', 'Orphaned version table (untouched by --drop-trigger)'],
                default => [$entry->tableName, '<fg=yellow>DROP TRIGGERS</>', 'Drop versioning triggers'],
            };
        }

        if ($mode === ExecutionMode::DROP_VERSION) {
            return match ($entry->status) {
                TableStatus::IGNORED => [$entry->tableName, '<fg=gray>IGNORED</>', 'Configured in ignore_tables'],
                TableStatus::ORPHANED => [$entry->tableName, '<fg=red>DROP VERSION</>', 'Drop orphaned version table'],
                TableStatus::UPDATE, TableStatus::UP_TO_DATE, TableStatus::MISSING_VERSION_COLUMN => [
                    $entry->tableName,
                    '<fg=red>DROP VERSION</>',
                    sprintf('Drop triggers & %s', $entry->versionTableName ?? ($entry->tableName . '_version')),
                ],
                TableStatus::CREATE, TableStatus::NO_VERSION => [$entry->tableName, 'NO VERSION TABLE', 'Nothing to drop'],
            };
        }

        return match ($entry->status) {
            TableStatus::CREATE => [
                $entry->tableName,
                '<fg=green>CREATE</>',
                $entry->note ?? 'Create version table and triggers',
            ],
            TableStatus::UPDATE => [
                $entry->tableName,
                '<fg=yellow>UPDATE</>',
                $entry->syncPlan !== null ? $this->formatSyncPlanDetails($entry->syncPlan) : 'Synchronize columns',
            ],
            TableStatus::UP_TO_DATE => [
                $entry->tableName,
                '<fg=cyan>UP TO DATE</>',
                $entry->note ?? 'Schema in sync; recreate triggers',
            ],
            TableStatus::NO_VERSION => [
                $entry->tableName,
                'NO VERSION',
                $entry->note ?? 'No version column (drop any stale triggers)',
            ],
            TableStatus::MISSING_VERSION_COLUMN => [
                $entry->tableName,
                '<fg=yellow>WARNING</>',
                $entry->note ?? 'No version column, but version table exists',
            ],
            TableStatus::ORPHANED => [
                $entry->tableName,
                '<fg=red>ORPHANED</>',
                $entry->note ?? 'No origin table found; version table will be dropped',
            ],
            TableStatus::IGNORED => [
                $entry->tableName,
                '<fg=gray>IGNORED</>',
                $entry->note ?? 'Configured in ignore_tables',
            ],
        };
    }

    private function formatSyncPlanDetails(TableSyncPlan $plan): string
    {
        $parts = [];
        foreach ($plan->addedColumns as $col) {
            $parts[] = sprintf('<fg=green>+ ADD %s</>', $col);
        }
        foreach ($plan->modifiedColumns as $col) {
            $parts[] = sprintf('<fg=yellow>~ MODIFY %s</>', $col);
        }
        foreach ($plan->droppedColumns as $col) {
            $parts[] = sprintf('<fg=red;options=bold>- DROP %s</>', $col);
        }

        return implode(', ', $parts);
    }

    /**
     * @param TableJobEntry[] $entries
     * @return string[]
     */
    private function collectWarnings(array $entries, ExecutionMode $mode): array
    {
        if ($mode !== ExecutionMode::SYNC) {
            return [];
        }

        $warnings = [];
        foreach ($entries as $entry) {
            if ($entry->status === TableStatus::UPDATE && $entry->syncPlan !== null && $entry->syncPlan->droppedColumns !== []) {
                $warnings[] = sprintf(
                    'Dropping column(s) from %s will permanently delete historical values: %s',
                    $entry->syncPlan->versionTableName,
                    implode(', ', $entry->syncPlan->droppedColumns),
                );
            } elseif ($entry->status === TableStatus::ORPHANED) {
                $warnings[] = sprintf(
                    'Orphaned version table "%s" has no origin table and will be dropped.',
                    $entry->tableName,
                );
            } elseif ($entry->status === TableStatus::MISSING_VERSION_COLUMN) {
                $warnings[] = sprintf(
                    'Table "%s" has no "version" column, but "%s" still exists (triggers dropped; version table kept).',
                    $entry->tableName,
                    $entry->versionTableName ?? ($entry->tableName . '_version'),
                );
            }
        }

        return $warnings;
    }

    /**
     * @param TableJobEntry[] $entries
     * @param string[] $sql
     * @return array{
     *     tablesInspected: int,
     *     tablesIgnored: int,
     *     versionTablesCreated: int,
     *     versionTablesUpdated: int,
     *     versionTablesDropped: int,
     *     columnsAdded: int,
     *     columnsModified: int,
     *     columnsDropped: int,
     *     triggersDropped: int,
     *     triggersCreated: int,
     *     totalSqlStatements: int,
     * }
     */
    private function computeStatistics(array $entries, ExecutionMode $mode, array $sql): array
    {
        $tablesIgnored = 0;
        $versionTablesCreated = 0;
        $versionTablesUpdated = 0;
        $versionTablesDropped = 0;
        $columnsAdded = 0;
        $columnsModified = 0;
        $columnsDropped = 0;

        foreach ($entries as $entry) {
            if ($entry->status === TableStatus::IGNORED) {
                $tablesIgnored++;
                continue;
            }

            if ($mode === ExecutionMode::SYNC) {
                if ($entry->status === TableStatus::CREATE) {
                    $versionTablesCreated++;
                } elseif ($entry->status === TableStatus::UPDATE && $entry->syncPlan !== null) {
                    $versionTablesUpdated++;
                    $columnsAdded += count($entry->syncPlan->addedColumns);
                    $columnsModified += count($entry->syncPlan->modifiedColumns);
                    $columnsDropped += count($entry->syncPlan->droppedColumns);
                } elseif ($entry->status === TableStatus::ORPHANED) {
                    $versionTablesDropped++;
                }
            } elseif ($mode === ExecutionMode::DROP_VERSION) {
                if (in_array($entry->status, [
                    TableStatus::ORPHANED,
                    TableStatus::UPDATE,
                    TableStatus::UP_TO_DATE,
                    TableStatus::MISSING_VERSION_COLUMN,
                ], true)) {
                    $versionTablesDropped++;
                }
            }
        }

        $triggersDropped = 0;
        $triggersCreated = 0;
        foreach ($sql as $query) {
            $trimmed = ltrim($query);
            if (preg_match('/^DROP\s+TRIGGER\b/i', $trimmed) === 1) {
                $triggersDropped++;
            } elseif (preg_match('/^CREATE\s+.*\bTRIGGER\b/i', $trimmed) === 1) {
                $triggersCreated++;
            }
        }

        return [
            'tablesInspected' => count($entries),
            'tablesIgnored' => $tablesIgnored,
            'versionTablesCreated' => $versionTablesCreated,
            'versionTablesUpdated' => $versionTablesUpdated,
            'versionTablesDropped' => $versionTablesDropped,
            'columnsAdded' => $columnsAdded,
            'columnsModified' => $columnsModified,
            'columnsDropped' => $columnsDropped,
            'triggersDropped' => $triggersDropped,
            'triggersCreated' => $triggersCreated,
            'totalSqlStatements' => count($sql),
        ];
    }
}
