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
 * Formats generated SQL statements into a clean, directly executable MySQL/MariaDB SQL script
 * (including DELIMITER directives around CREATE TRIGGER blocks).
 */
final readonly class SqlScriptFormatter
{
    /**
     * @param string[] $sqlStatements
     */
    public function format(array $sqlStatements): string
    {
        if ($sqlStatements === []) {
            return '-- No SQL statements to execute.' . PHP_EOL;
        }

        $lines = [];
        $inTriggerBlock = false;

        foreach ($sqlStatements as $statement) {
            $trimmed = trim($statement);
            if ($trimmed === '') {
                continue;
            }

            $isTrigger = (bool) preg_match('/^CREATE\s+.*\bTRIGGER\b/i', $trimmed);

            if ($isTrigger) {
                if (!$inTriggerBlock) {
                    $lines[] = 'DELIMITER $$';
                    $inTriggerBlock = true;
                }
                $lines[] = rtrim($trimmed, ';') . '$$';
            } else {
                if ($inTriggerBlock) {
                    $lines[] = 'DELIMITER ;';
                    $inTriggerBlock = false;
                }
                $lines[] = rtrim($trimmed, ';') . ';';
            }
        }

        if ($inTriggerBlock) {
            $lines[] = 'DELIMITER ;';
        }

        if ($lines === []) {
            return '-- No SQL statements to execute.' . PHP_EOL;
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }
}
