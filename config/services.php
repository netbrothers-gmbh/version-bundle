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

use NetBrothers\VersionBundle\Command\MakeVersionCommand;
use NetBrothers\VersionBundle\Services\VersionConfigService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('NetBrothers\\VersionBundle\\', '../src/*')
        ->exclude('../src/{DependencyInjection,Entity,Tests,Resources,Services/VersionConfigService.php}');

    $services->set(VersionConfigService::class)
        ->arg('$config', param('netbrothers_version'));

    $services->set(MakeVersionCommand::class)
        ->public();
};
