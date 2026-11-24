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

namespace NetBrothers\VersionBundle\Services\Schema;

/**
 * Immutable summary of planned schema synchronization changes for a single `_version` table.
 */
final readonly class TableSyncPlan
{
    /**
     * @param string[] $addedColumns
     * @param string[] $modifiedColumns
     * @param string[] $droppedColumns
     * @param string[] $sql
     */
    public function __construct(
        public string $originTableName,
        public string $versionTableName,
        public array $addedColumns,
        public array $modifiedColumns,
        public array $droppedColumns,
        public array $sql,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->sql !== [];
    }
}
