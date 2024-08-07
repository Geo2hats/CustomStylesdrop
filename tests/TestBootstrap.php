<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$loader = (new TestBootstrapper())
    ->addCallingPlugin()
    ->addActivePlugins('CustomStylesdrop')
    ->bootstrap()
    ->getClassLoader();

$loader->addPsr4('CustomStylesdrop\\Tests\\', __DIR__);