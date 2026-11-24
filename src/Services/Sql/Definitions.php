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

final class Definitions
{
    /** @var string Setting version to 1 before insert */
    public const string TRIGGER_NAME_BEFORE_INSERT_SET_VERSION = 'BEFORE_INSERT_SET_VERSION';

    /** @var string Legacy v4 trigger name kept for automatic DROP TRIGGER cleanup during upgrade */
    public const string LEGACY_TRIGGER_NAME_BEFORE_INSERT_SET_VERSION = 'BEVOR_INSERT_SET_VERSION';

    /** @var string Create first entry in version table */
    public const string TRIGGER_NAME_AFTER_INSERT_INSERT_VERSION = 'AFTER_INSERT_INSERT_VERSION';

    /** @var string Increment column version before update */
    public const string TRIGGER_NAME_BEFORE_UPDATE_SET_VERSION = 'BEFORE_UPDATE_SET_VERSION';

    /** @var string Insert new revision into version table on update */
    public const string TRIGGER_NAME_AFTER_UPDATE_INSERT_VERSION = 'AFTER_UPDATE_INSERT_VERSION';

    /** @var string Postfix for version table */
    public const string VERSION_TABLE_NAME_POSTFIX = '_version';

    /** @var string Postfix for version foreign key constraint */
    public const string FK_POSTFIX = '_fk_c88697';

    /** @var int Maximum MySQL/MariaDB identifier length */
    public const int MAX_IDENTIFIER_LENGTH = 64;
}
