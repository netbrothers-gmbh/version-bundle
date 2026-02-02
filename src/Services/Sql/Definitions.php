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

use Doctrine\DBAL\Schema\Table;

final class Definitions
{
    /** @var string  setting version to int 1 before insert */
    public const string TRIGGER_NAME_BEFORE_INSERT_SET_VERSION = 'BEVOR_INSERT_SET_VERSION';
    /** @var string  create first entry in table version */
    public const string TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION = 'AFTER_INSERT_INSERT_VERSION';
    /** @var string increment column version before update */
    public const string TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION = 'BEFORE_UPDATE_SET_VERSION';
    /** @var string insert in table version on update */
    public const string TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION = 'AFTER_UPDATE_INSERT_VERSION';
    /** @var string postix for version table */
    public const string VERSION_TABLE_NAME_POSTFIX = '_version';
    /** @var string sql create version table */
    public const string SQL_CREATE_TABLE = 'CREATE TABLE `%s`.`%s` AS SELECT * FROM `%s`.`%s`;';
    /** @var string sql alter table add primary keys to version table */
    public const string SQL_ADD_PK_TO_VERSION = 'ALTER TABLE `%s`.`%s` ADD PRIMARY KEY (`id`,`version`);';
    /** @var string sql add constraint to version */
    public const string SQL_ADD_CONSTRAINT = "ALTER TABLE `%s`.`%s` ADD CONSTRAINT `%s_fk_c88697` FOREIGN KEY (`id`) REFERENCES `%s` (`id`) ON DELETE CASCADE;";
    /** @var string sql drop table */
    public const string SQL_DROP_TABLE = "DROP TABLE IF EXISTS `%s`.`%s`;";
}
