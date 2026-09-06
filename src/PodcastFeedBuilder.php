<?php

declare(strict_types=1);

namespace Podcast;

use DateTimeImmutable;
use DOMDocument;
use Laminas\Feed\Writer\Feed;
use Laminas\Feed\Writer\Writer;
use Stashd\PluginSdk\Item;
use Stashd\PluginSdk\ItemResource;
use Stashd\PluginSdk\PublishRequest;

final class PodcastFeedBuilder
{
    private const AUDIO_DERIVATION = 'podcast-audio-v1';

    private const PODCAST_NS = 'https://podcastindex.org/namespace/1.0';

    public function build(PublishRequest $request, PodcastFeedConfig $config): string
    {
        Writer::registerExtension('PodcastIndex');

        $feed = new Feed();
        $title = $config->title;
        $description = $config->description;
        $publicationUrl = $config->publicationUrl?->toString();
        $siteUrl = $config->linkUrl?->toString();
        $image = $this->feedImage($request, $config);

        $feed->setTitle($title)->setDescription($description);
        $feed->setLanguage($config->language);

        if ($siteUrl !== null && $siteUrl !== '') {
            $feed->setLink($siteUrl);
        }

        if ($publicationUrl !== null && $publicationUrl !== '') {
            $feed->setFeedLink($publicationUrl, 'rss');
        }

        if ($image !== null && ($siteUrl !== null || $publicationUrl !== null)) {
            $feed->setImage(['uri' => $image, 'title' => $title, 'link' => $siteUrl ?: $publicationUrl]);
        }

        $this->call($feed, 'setItunesExplicit', [$config->explicit]);

        if ($config->author !== null) {
            $this->call($feed, 'addItunesAuthor', [$config->author]);
        }

        if ($config->categories !== []) {
            $this->call($feed, 'setItunesCategories', [$config->categories]);
        }

        if ($config->complete) {
            $this->call($feed, 'setItunesComplete', [true]);
        }
        $this->call($feed, 'setItunesType', [$config->podcastType]);

        if ($image !== null && $this->hasLiteralImageExtension($image)) {
            $this->call($feed, 'setItunesImage', [$image]);
        }

        if ($config->podcastGuid !== null) {
            $this->call($feed, 'setPodcastIndexGuid', [['value' => $config->podcastGuid]]);
        }
        $this->call($feed, 'setPodcastIndexMedium', [['value' => $config->mediaKind === 'video' ? 'video' : 'podcast']]);

        if ($config->fundingUrl !== null) {
            $this->call($feed, 'addPodcastIndexFunding', [[
                'url' => $config->fundingUrl->toString(),
                'title' => $config->fundingLabel,
            ]]);
        }

        if ($image !== null) {
            $this->call($feed, 'addPodcastIndexDetailedImage', [['href' => $image, 'purpose' => 'icon']]);
        }

        $total = count($request->items);
        $request->progress?->report(sprintf('Publishing feed · 0 of %d', $total), 0.5);

        foreach ($request->items as $index => $item) {
            $resource = $this->selectedResource($item, $config);

            if ($resource === null || $resource->url === null || $resource->url === '') {
                continue;
            }
            $entry = $feed->createEntry();
            $entry->setId($item->id)->setTitle($item->title);

            if (($description = $item->description) !== null && $description !== '') {
                $entry->setDescription($description)->setContent($description);
            }

            if (($date = $this->date($item->publishedAt)) !== null) {
                $entry->setDateCreated($date);
            }
            $entry->setEnclosure([
                'uri' => $resource->url,
                'type' => $resource->mediaType ?? ($config->mediaKind === 'video' ? 'video/mp4' : 'audio/mpeg'),
                'length' => $resource->sizeBytes,
            ]);

            $itemImage = $this->resource($item, 'image');
            $entryItunes = $entry->getExtension('ITunes');
            $entryPodcast = $entry->getExtension('PodcastIndex');

            if ($entryItunes === null || $entryPodcast === null) {
                throw new \RuntimeException('Laminas podcast extensions are unavailable.');
            }

            if ($item->durationSeconds !== null) {
                $this->call($entryItunes, 'setItunesDuration', [$this->duration($item->durationSeconds)]);
            }

            if ($itemImage?->url !== null && $itemImage->url !== '') {
                if ($this->hasLiteralImageExtension($itemImage->url)) {
                    $this->call($entryItunes, 'setItunesImage', [$itemImage->url]);
                }
                $this->call($entryPodcast, 'addPodcastIndexDetailedImage', [['href' => $itemImage->url, 'purpose' => 'icon']]);
            }

            if ($config->captions !== 'off') {
                $transcript = $this->transcript($item, $config);

                if ($transcript?->url !== null && $transcript->url !== '') {
                    $this->call($entryPodcast, 'setPodcastIndexTranscript', [[
                        'url' => $transcript->url,
                        'type' => $transcript->mediaType ?? 'text/vtt',
                        'language' => $config->captionLanguages[0] ?? null,
                    ]]);
                }
            }
            $feed->addEntry($entry);
            $request->progress?->report(sprintf('Publishing feed · %d of %d', $index + 1, $total), 0.5 + ($total > 0 ? ($index + 1) / $total * 0.5 : 0.5));
        }

        $xml = $feed->export('rss', true);

        return $this->restoreRoutedImages($xml, $image, $request, $config);
    }

    private function feedImage(PublishRequest $request, PodcastFeedConfig $config): ?string
    {
        $image = $config->imageUrl?->toString();

        if ($image !== null && $image !== '') {
            return $image;
        }

        foreach ($request->items as $item) {
            $resource = $this->resource($item, 'image');

            if ($resource?->url !== null && $resource->url !== '') {
                return $resource->url;
            }
        }

        return null;
    }

    private function hasLiteralImageExtension(string $url): bool
    {
        return preg_match('/\.(?:jpg|png)$/i', $url) === 1;
    }

    private function restoreRoutedImages(string $xml, ?string $feedImage, PublishRequest $request, PodcastFeedConfig $config): string
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
        $channelNodes = $xpath->query('/rss/channel');
        $channel = $channelNodes instanceof \DOMNodeList ? $channelNodes->item(0) : null;

        $channelImages = $channel instanceof \DOMElement ? $xpath->query('./itunes:image', $channel) : false;

        if ($channel instanceof \DOMElement && $feedImage !== null && $channelImages instanceof \DOMNodeList && $channelImages->length === 0) {
            $image = $document->createElementNS('http://www.itunes.com/dtds/podcast-1.0.dtd', 'itunes:image');
            $image->setAttribute('href', $feedImage);
            $channel->appendChild($image);
        }
        $items = $xpath->query('/rss/channel/item');

        if (! $items instanceof \DOMNodeList) {
            return $document->saveXML() ?: $xml;
        }
        $itemIndex = 0;

        foreach ($request->items as $item) {
            $resource = $this->selectedResource($item, $config);

            if ($resource === null || $resource->url === null || $resource->url === '') {
                continue;
            }
            $itemImage = $this->resource($item, 'image');
            $node = $items->item($itemIndex++);

            if (! $node instanceof \DOMElement || $itemImage?->url === null || $itemImage->url === '') {
                continue;
            }
            $itemImages = $xpath->query('./itunes:image', $node);

            if (! $itemImages instanceof \DOMNodeList || $itemImages->length > 0) {
                continue;
            }
            $image = $document->createElementNS('http://www.itunes.com/dtds/podcast-1.0.dtd', 'itunes:image');
            $image->setAttribute('href', $itemImage->url);
            $node->appendChild($image);
        }

        $document->documentElement?->setAttribute('xmlns:podcast', self::PODCAST_NS);

        return $document->saveXML() ?: $xml;
    }

    private function selectedResource(Item $item, PodcastFeedConfig $config): ?ItemResource
    {
        return $config->mediaKind === 'video' ? $this->resource($item, 'video') : $this->audioResource($item);
    }

    private function audioResource(Item $item): ?ItemResource
    {
        foreach ($item->resources as $resource) {
            if ($resource->kind === 'audio' && $resource->derivationKey === null) {
                return $resource;
            }
        }

        foreach ($item->resources as $resource) {
            if ($resource->kind === 'audio' && $resource->derivationKey === self::AUDIO_DERIVATION) {
                return $resource;
            }
        }

        return null;
    }

    private function resource(Item $item, string $kind): ?ItemResource
    {
        foreach ($item->resources as $resource) {
            if ($resource->kind === $kind) {
                return $resource;
            }
        }

        return null;
    }

    private function transcript(Item $item, PodcastFeedConfig $config): ?ItemResource
    {
        $language = $config->captionLanguages === [] ? '' : $config->captionLanguages[0];

        foreach ($item->resources as $resource) {
            if ($resource->kind === 'subtitle' && ($language === '' || str_contains(strtolower($resource->reference), strtolower($language)))) {
                return $resource;
            }
        }

        return null;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** @param list<mixed> $arguments */
    private function call(object $target, string $method, array $arguments): mixed
    {
        return $target->{$method}(...$arguments);
    }
}
