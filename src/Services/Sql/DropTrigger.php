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

namespace NetBrothers\VersionBundle\Services\Sql;

use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * Generates SQL statements for dropping versioning triggers (including legacy v4 trigger names).
 */
final readonly class DropTrigger
{
    public function __construct(
        private IdentifierHelper $identifierHelper,
    ) {
    }

    /**
     * Generates DROP TRIGGER IF EXISTS statements for all versioning triggers of a table.
     *
     * @return string[]
     */
    public function getSql(string $databaseName, string $tableName, AbstractPlatform $platform): array
    {
        $quotedDb = $platform->quoteSingleIdentifier($databaseName);

        $suffixes = [
            Definitions::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION,
            Definitions::LEGACY_TRIGGER_NAME_BEFORE_INSERT_SET_VERSION,
            Definitions::TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION,
            Definitions::TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION,
            Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION,
        ];

        $sql = [];
        foreach ($suffixes as $suffix) {
            $triggerName = $this->identifierHelper->buildTriggerName($tableName, $suffix);
            $sql[] = sprintf(
                'DROP TRIGGER IF EXISTS %s.%s',
                $quotedDb,
                $platform->quoteSingleIdentifier($triggerName),
            );
        }

        return $sql;
    }
}
