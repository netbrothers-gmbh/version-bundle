<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 16.03.21
 *
 */

namespace NetBrothers\VersionBundle;

use NetBrothers\VersionBundle\DependencyInjection\NetBrothersVersionExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Class NetBrothersVersionBundle
 * @package NetBrothers\VersionBundle
 */
class NetBrothersVersionBundle extends Bundle
{

    /**
     * @return NetBrothersVersionExtension|ExtensionInterface|null
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $this->extension = new NetBrothersVersionExtension();
        }
        return $this->extension;
    }
}
