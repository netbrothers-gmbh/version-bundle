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

namespace NetBrothers\VersionBundle\Services;

final readonly class VersionConfigService
{
    private const array DEFAULT_IGNORE_COLUMNS = ['id', 'version', 'created_at', 'updated_at'];

    public function __construct(
        private array $config
    ) {
    }

    public function getIgnoreTables(): array
    {
        return $this->config['ignore_tables'] ?? [];
    }

    public function getIgnoreColumns(): array
    {
        $userDefined = $this->config['ignore_columns'] ?? [];
        return array_unique(array_merge(self::DEFAULT_IGNORE_COLUMNS, $userDefined));
    }
}
