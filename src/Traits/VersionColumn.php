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

namespace NetBrothers\VersionBundle\Traits;

use Doctrine\ORM\Mapping as ORM;

trait VersionColumn
{
    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1})
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 1])]
    protected int $version = 1;

    public function getVersion(): int
    {
        return $this->version;
    }
}
