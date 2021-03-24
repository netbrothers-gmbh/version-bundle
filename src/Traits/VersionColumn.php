<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 23.03.21
 *
 */

namespace NetBrothers\VersionBundle\Traits;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trait VersionColumn
 * @package NetBrothers\VersionBundle\Traits
 */
trait VersionColumn
{
    /**
     * @ORM\Column(type="integer", nullable=true, options={"default" : 1})
     * @var int
     */
    protected $version = 1;

    /**
     * @return int
     */
    public function getVersion(): ?int
    {
        return $this->version;
    }

}