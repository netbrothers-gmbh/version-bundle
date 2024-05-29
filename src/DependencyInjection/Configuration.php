<?php
/**
 * NetBrothers VersionBundle
 *
 * @author Stefan Wessel, NetBrothers GmbH
 * @date 16.03.21
 *
 */

namespace NetBrothers\VersionBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Class Configuration
 * @package NetBrothers\VersionBundle\DependencyInjection
 */
class Configuration implements ConfigurationInterface
{

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('netbrothers_version');
        $rootNode = $treeBuilder->getRootNode();
        $rootNode
            ->fixXmlConfig('ignore_table', 'ignore_tables')
            ->fixXmlConfig('ignore_column', 'ignore_columns')
            ->children()
                ->arrayNode('ignore_tables')
                    ->info('tables which should not be recognized')
                    ->prototype('scalar')->end()
                ->end()
                ->arrayNode('ignore_columns')
                    ->info('columns which should not be compared')
                    ->prototype('scalar')->end()
                ->end()
            ->end()
        ;
        return $treeBuilder;

    }
}
