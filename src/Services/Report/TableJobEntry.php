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

/**
 * Structured status record for a single table inspected by JobService.
 */
final readonly class TableJobEntry
{
    public function __construct(
        public string $tableName,
        public TableStatus $status,
        public ?string $versionTableName = null,
        public ?TableSyncPlan $syncPlan = null,
        public ?string $note = null,
    ) {
    }
}
