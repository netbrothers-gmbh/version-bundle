<?php

declare(strict_types=1);

namespace NetBrothers\VersionBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Project: netbrothers-gmbh/version-bundle
 * 
 * @author Thilo Ratnaweera <info@netbrothers.de>
 * @copyright © 2021 NetBrothers GmbH.
 * @license All rights reserved.
 */
final class MakeVersionStandaloneEditionCommand extends MakeVersionCommand
{
    private EntityManagerInterface $entityManager;
    protected static $defaultName = 'nb-versions';

    protected function configure()
    {
        parent::configure();
        $this->addOption(
            'container-file',
            'c',
            InputOption::VALUE_REQUIRED,
            'Path to a PHP file which will return a PSR-11 compatible DI-Container.'
        );
        $this->addOption(
            'ignore-table',
            'i',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Add as many `--ignore-table=` options as you need.'
        );
        $this->addOption(
            'exclude-column',
            'x',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Add as many `--exclude-column=` options as you need.'
        );
    }

    public function __construct()
    {
        $initLater = true;
        parent::__construct(null, [], [], $initLater);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initEntityManager($input);
        parent::initCommand(
            $this->entityManager,
            $input->getOption('ignore-table'),
            $input->getOption('exclude-column')
        );
        parent::execute($input, $output);
    }

    private function initEntityManager(InputInterface $input)
    {
        $containerFile = $input->getOption('container-file');
        if (empty($containerFile)) {
            $excMsg = 'No valid container file given. Please use the ';
            $excMsg .= '--container-file= option.';
            throw new Exception($excMsg);
        }
        if (!is_readable($containerFile)) {
            throw new Exception(sprintf(
                'The container file %s is not readable.',
                $containerFile
            ));
        }
        $container = require $containerFile;
        if (!($container instanceof ContainerInterface)) {
            throw new Exception(sprintf(
                'The container file %s does not yield a PSR-11 compatible container.',
                $containerFile
            ));
        }

        $this->entityManager = $container->get(EntityManagerInterface::class);
        if (!($this->entityManager instanceof EntityManagerInterface)) {
            throw new Exception(sprintf(
                'No `%s` is registered with the container.',
                EntityManagerInterface::class
            ));
        }
    }
}
