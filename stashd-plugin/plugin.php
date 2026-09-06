<?php

declare(strict_types=1);

require_once '/sdk/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

(new Stashd\PluginSdk\Runtime\PluginServer(new Podcast\PodcastBroadcast()))->run();
