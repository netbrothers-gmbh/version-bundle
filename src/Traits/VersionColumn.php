<?php

/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 */

namespace NetBrothers\VersionBundle\Traits;

use Doctrine\ORM\Mapping as ORM;

trait VersionColumn
{
    /**
     * @ORM\Column(type="integer", nullable=false, options={"default": 1})
     */
    #[ORM\Column(type: 'integer', nullable: false, options: ['default' => 1])]
    private int $version = 1;

    public function getVersion(): int
    {
        return $this->version;
    }
}
