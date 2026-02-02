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

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;

return static function (DefinitionConfigurator $definition): void {
    $definition->rootNode()
        ->children()
            ->arrayNode('ignore_tables')
                ->info('Tables which should not be recognized')
                ->prototype('scalar')->end()
            ->end()
            ->arrayNode('ignore_columns')
                ->info('Columns which should not be compared')
                ->prototype('scalar')->end()
            ->end()
        ->end()
    ;
};
