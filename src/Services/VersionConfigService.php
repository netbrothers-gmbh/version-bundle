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
    /**
     * @param array{ignore_tables?: string[], ignore_columns?: string[]} $config
     */
    public function __construct(
        private array $config
    ) {
    }

    /** @return string[] */
    public function getIgnoreTables(): array
    {
        return array_values(array_unique($this->config['ignore_tables'] ?? []));
    }

    /** @return string[] */
    public function getIgnoreColumns(): array
    {
        return array_values(array_unique($this->config['ignore_columns'] ?? []));
    }
}
