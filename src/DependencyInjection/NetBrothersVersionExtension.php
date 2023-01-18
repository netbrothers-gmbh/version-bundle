<?php

/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 */

namespace NetBrothers\VersionBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class NetBrothersVersionExtension extends Extension
{
    /**
     * Loads a specific configuration.
     *
     * @param array $configs
     * @param ContainerBuilder $container
     * @throws \Exception When provided tag is not defined in this extension
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../Resources/config')
        );
        $loader->load('services.xml');
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);
        $commandMakeVersion = $container->getDefinition('netbrothers_version.command.make_version');
        $commandMakeVersion->setArgument(1, $config['ignore_tables']);
        $commandMakeVersion->setArgument(2, $config['ignore_columns']);
    }

    public function getAlias(): string
    {
        return 'netbrothers_version';
    }
}
