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

/**
 * generating sql for dropping triggers
 */
final class DropTrigger
{
    /**
     * generates sql-Statement for dropping triggers
     * @return string[]
     */
    public function getSql(string $databaseName, string $tableName): array
    {
        $fDrop = $this->createDropStatement($databaseName, $tableName);

        return [
            sprintf($fDrop, Definitions::TRIGGER_NAME_BEFORE_INSERT_SET_VERSION),
            sprintf($fDrop, Definitions::TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION),
            sprintf($fDrop, Definitions::TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION),
            sprintf($fDrop, Definitions::TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION),
        ];
    }

    private function createDropStatement(string $databaseName, string $tableName): string
    {
        return sprintf("DROP TRIGGER IF EXISTS `%s`.`%s_%%s`;", $databaseName, $tableName);
    }
}
