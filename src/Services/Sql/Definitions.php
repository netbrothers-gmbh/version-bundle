<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 19.03.21
 *
 */

namespace NetBrothers\VersionBundle\Services\Sql;

use Doctrine\DBAL\Schema\Table;

/**
 * Class Definitions
 * @package NetBrothers\VersionBundle\Services\Sql
 */
class Definitions
{
    /** @var string  setting version to int 1 before insert */
    const TRIGGER_NAME_BEFORE_INSERT_SET_VERSION = 'BEVOR_INSERT_SET_VERSION';
    /** @var string  create first entry in table version */
    const TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION = 'AFTER_INSERT_INSERT_VERSION';
    /** @var string increment column version before update */
    const TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION = 'BEFORE_UPDATE_SET_VERSION';
    /** @var string insert in table version on update */
    const TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION = 'AFTER_UPDATE_INSERT_VERSION';
    /** @var string postix for version table */
    const VERSION_TABLE_NAME_POSTFIX = '_version';
    /** @var string sql create version table */
    const SQL_CREATE_TABLE = 'CREATE TABLE `%s`.`%s` AS SELECT * FROM `%s`.`%s`;';
    /** @var string sql alter table add primary keys to version table */
    const SQL_ADD_PK_TO_VERSION = 'ALTER TABLE `%s`.`%s` ADD PRIMARY KEY (`id`,`version`);';
    /** @var string sql add constraint to version */
    const SQL_ADD_CONSTRAINT = "ALTER TABLE `%s`.`%s` ADD CONSTRAINT `%s_fk_c88697` FOREIGN KEY (`id`) REFERENCES `%s` (`id`) ON DELETE CASCADE;";
    /** @var string sql drop table */
    const SQL_DROP_TABLE = "DROP TABLE IF EXISTS `%s`.`%s`;";


    /** table name ends with version
     * @param Table $table
     * @return bool
     */
    public function isVersionTable(Table $table): bool
    {
        return (preg_match("/" . self::VERSION_TABLE_NAME_POSTFIX ."$/", $table->getName()));
    }

    /** table name does not end with version
     * @param Table $table
     * @return bool
     */
    public function isOriginTable(Table $table): bool
    {
        return (!preg_match("/" . self::VERSION_TABLE_NAME_POSTFIX ."$/", $table->getName()));
    }

    /**
     * @param Table $table
     * @return bool
     */
    public function hasTableVersionColumn(Table $table): bool
    {
        return $table->hasColumn('version');
    }
}