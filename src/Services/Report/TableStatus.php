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

enum TableStatus: string
{
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case UP_TO_DATE = 'UP TO DATE';
    case NO_VERSION = 'NO VERSION';
    case MISSING_VERSION_COLUMN = 'WARNING';
    case ORPHANED = 'ORPHANED';
    case IGNORED = 'IGNORED';
}
