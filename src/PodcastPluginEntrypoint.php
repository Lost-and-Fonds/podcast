<?php

declare(strict_types=1);

namespace Podcast;

use Stashd\PluginSdk\PluginEntrypoint;
use Stashd\PluginSdk\PluginRegistry;

final class PodcastPluginEntrypoint implements PluginEntrypoint
{
    public function register(PluginRegistry $registry): void
    {
        $registry->broadcast('default', new PodcastBroadcast());
        $registry->collectionExporter('podcast-opml', new PodcastOpmlExporter());
    }
}
