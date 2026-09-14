<?php

declare(strict_types=1);

namespace Podcast;

use DOMDocument;
use Stashd\PluginSdk\ExportedFile;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\StashCollection;
use Stashd\PluginSdk\StashCollectionExporter;

final class PodcastOpmlExporter implements StashCollectionExporter
{
    public function key(): string
    {
        return 'podcast-opml';
    }

    public function label(): string
    {
        return 'Podcast subscriptions (OPML)';
    }

    public function export(StashCollection $collection, PluginContext $context): ExportedFile
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $opml = $document->createElement('opml');
        $opml->setAttribute('version', '2.0');
        $document->appendChild($opml);
        $head = $document->createElement('head');
        $head->appendChild($document->createElement('title', 'Stashd podcast subscriptions'));
        $opml->appendChild($head);
        $body = $document->createElement('body');
        $opml->appendChild($body);

        foreach ($collection->entries as $entry) {
            if ($entry->broadcastKey !== 'podcast') {
                continue;
            }

            $outline = $document->createElement('outline');
            $outline->setAttribute('type', 'rss');
            $outline->setAttribute('text', $entry->stashName);
            $outline->setAttribute('title', $entry->stashName);
            $outline->setAttribute('xmlUrl', $entry->publicUrl);
            $body->appendChild($outline);
        }

        return new ExportedFile('stashd-podcasts.opml', 'text/x-opml', $document->saveXML() ?: '');
    }
}
