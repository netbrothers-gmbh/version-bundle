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

enum ExecutionMode: string
{
    case SYNC = 'sync';
    case DROP_TRIGGER = 'drop-trigger';
    case DROP_VERSION = 'drop-version';

    public function label(): string
    {
        return match ($this) {
            self::SYNC => 'Synchronize version tables & triggers',
            self::DROP_TRIGGER => 'Drop triggers only',
            self::DROP_VERSION => 'Drop triggers & version tables',
        };
    }
}
