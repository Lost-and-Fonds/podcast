<?php

declare(strict_types=1);

require_once '/sdk/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

$registry = Stashd\PluginSdk\PluginBootstrap::load(new Podcast\PodcastPluginEntrypoint());

(new Stashd\PluginSdk\Runtime\PluginServer($registry))->run();
