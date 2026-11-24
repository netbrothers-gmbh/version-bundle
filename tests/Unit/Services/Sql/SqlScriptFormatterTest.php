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

use NetBrothers\VersionBundle\Services\Sql\SqlScriptFormatter;
use PHPUnit\Framework\TestCase;

final class SqlScriptFormatterTest extends TestCase
{
    private SqlScriptFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new SqlScriptFormatter();
    }

    public function testFormatsEmptySqlAsComment(): void
    {
        self::assertSame('-- No SQL statements to execute.' . PHP_EOL, $this->formatter->format([]));
        self::assertSame('-- No SQL statements to execute.' . PHP_EOL, $this->formatter->format(['   ']));
    }

    public function testFormatsStandardStatementsAndWrapsTriggersWithDelimiter(): void
    {
        $sql = [
            'DROP TRIGGER IF EXISTS `app`.`protocol_BEFORE_INSERT_SET_VERSION`',
            'ALTER TABLE protocol_version ADD client_revision INT DEFAULT 0;',
            "CREATE DEFINER = CURRENT_USER TRIGGER `app`.`protocol_BEFORE_INSERT_SET_VERSION` BEFORE INSERT ON `protocol`\nFOR EACH ROW\nBEGIN\n    SET NEW.`version` = 1;\nEND",
            "CREATE DEFINER = CURRENT_USER TRIGGER `app`.`protocol_BEFORE_UPDATE_SET_VERSION` BEFORE UPDATE ON `protocol`\nFOR EACH ROW\nBEGIN\n    SET NEW.`version` = COALESCE(OLD.`version`, 0) + 1;\nEND",
            'DROP TABLE IF EXISTS `old_version`',
        ];

        $formatted = $this->formatter->format($sql);

        $expected = implode(PHP_EOL, [
            'DROP TRIGGER IF EXISTS `app`.`protocol_BEFORE_INSERT_SET_VERSION`;',
            'ALTER TABLE protocol_version ADD client_revision INT DEFAULT 0;',
            'DELIMITER $$',
            "CREATE DEFINER = CURRENT_USER TRIGGER `app`.`protocol_BEFORE_INSERT_SET_VERSION` BEFORE INSERT ON `protocol`\nFOR EACH ROW\nBEGIN\n    SET NEW.`version` = 1;\nEND\$\$",
            "CREATE DEFINER = CURRENT_USER TRIGGER `app`.`protocol_BEFORE_UPDATE_SET_VERSION` BEFORE UPDATE ON `protocol`\nFOR EACH ROW\nBEGIN\n    SET NEW.`version` = COALESCE(OLD.`version`, 0) + 1;\nEND\$\$",
            'DELIMITER ;',
            'DROP TABLE IF EXISTS `old_version`;',
        ]) . PHP_EOL;

        self::assertSame($expected, $formatted);
    }
}
