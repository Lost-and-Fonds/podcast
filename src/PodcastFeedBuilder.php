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

    /** @param array<string, mixed> $settings */
    public function build(PublishRequest $request, array $settings): string
    {
        Writer::registerExtension('PodcastIndex');

        $feed = new Feed();
        $title = $this->text($settings, 'title') ?? 'Stashd Podcast';
        $description = $this->text($settings, 'description') ?: $title;
        $publicationUrl = $this->text($settings, 'publication_url');
        $siteUrl = $this->text($settings, 'link_url');
        $image = $this->feedImage($request, $settings);

        $feed->setTitle($title)->setDescription($description);
        $feed->setLanguage($this->text($settings, 'language') ?? 'en');

        if ($siteUrl !== null && $siteUrl !== '') {
            $feed->setLink($siteUrl);
        }

        if ($publicationUrl !== null && $publicationUrl !== '') {
            $feed->setFeedLink($publicationUrl, 'rss');
        }

        if ($image !== null && ($siteUrl !== null || $publicationUrl !== null)) {
            $feed->setImage(['uri' => $image, 'title' => $title, 'link' => $siteUrl ?: $publicationUrl]);
        }

        $this->call($feed, 'setItunesExplicit', [$this->bool($settings, 'explicit')]);

        if (($author = $this->text($settings, 'author')) !== null && $author !== '') {
            $this->call($feed, 'addItunesAuthor', [$author]);
        }

        if (($categories = $this->categories($settings)) !== []) {
            $this->call($feed, 'setItunesCategories', [$categories]);
        }

        if ($this->bool($settings, 'complete')) {
            $this->call($feed, 'setItunesComplete', [true]);
        }
        $this->call($feed, 'setItunesType', [$this->text($settings, 'podcast_type') ?? 'episodic']);

        if ($image !== null && $this->hasLiteralImageExtension($image)) {
            $this->call($feed, 'setItunesImage', [$image]);
        }

        if (($guid = $this->text($settings, 'podcast_guid') ?? $this->text($settings, 'guid')) !== null && $guid !== '') {
            $this->call($feed, 'setPodcastIndexGuid', [['value' => $guid]]);
        }
        $this->call($feed, 'setPodcastIndexMedium', [['value' => $this->text($settings, 'media_kind') === 'video' ? 'video' : 'podcast']]);

        if (($fundingUrl = $this->text($settings, 'funding_url')) !== null && $fundingUrl !== '') {
            $this->call($feed, 'addPodcastIndexFunding', [[
                'url' => $fundingUrl,
                'title' => $this->text($settings, 'funding_label') ?: 'Support this podcast',
            ]]);
        }

        if ($image !== null) {
            $this->call($feed, 'addPodcastIndexDetailedImage', [['href' => $image, 'purpose' => 'icon']]);
        }

        $total = count($request->items);

        foreach ($request->items as $index => $item) {
            $resource = $this->selectedResource($item, $settings);

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
                'type' => $resource->mediaType ?? ($settings['media_kind'] === 'video' ? 'video/mp4' : 'audio/mpeg'),
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

            if ($this->captionsEnabled($settings)) {
                $transcript = $this->transcript($item, $settings);

                if ($transcript?->url !== null && $transcript->url !== '') {
                    $this->call($entryPodcast, 'setPodcastIndexTranscript', [[
                        'url' => $transcript->url,
                        'type' => $transcript->mediaType ?? 'text/vtt',
                        'language' => $this->captionLanguage($settings),
                    ]]);
                }
            }
            $feed->addEntry($entry);
            $request->progress?->report(sprintf('Published item %d of %d: %s', $index + 1, $total, $item->title), 0.5 + ($total > 0 ? ($index + 1) / $total * 0.5 : 0.5));
        }

        $xml = $feed->export('rss', true);

        return $this->restoreRoutedImages($xml, $image, $request, $settings);
    }

    /** @param array<string, mixed> $settings */
    private function feedImage(PublishRequest $request, array $settings): ?string
    {
        $image = $this->text($settings, 'image_url');

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

    /** @param array<string, mixed> $settings
     * @return list<string>
     */
    private function categories(array $settings): array
    {
        $value = $this->text($settings, 'categories') ?? '';

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function hasLiteralImageExtension(string $url): bool
    {
        return preg_match('/\.(?:jpg|png)$/i', $url) === 1;
    }

    /** @param array<string, mixed> $settings */
    private function restoreRoutedImages(string $xml, ?string $feedImage, PublishRequest $request, array $settings): string
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
            $resource = $this->selectedResource($item, $settings);

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

    /** @param array<string, mixed> $settings */
    private function selectedResource(Item $item, array $settings): ?ItemResource
    {
        return ($settings['media_kind'] ?? 'audio') === 'video' ? $this->resource($item, 'video') : $this->audioResource($item);
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

    /** @param array<string, mixed> $settings */
    private function transcript(Item $item, array $settings): ?ItemResource
    {
        $languages = array_filter(array_map('trim', explode(',', $this->text($settings, 'caption_languages') ?? 'en')));

        foreach ($item->resources as $resource) {
            if ($resource->kind === 'subtitle' && ($languages === [] || str_contains(strtolower($resource->reference), strtolower((string) $languages[0])))) {
                return $resource;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $settings */
    private function captionLanguage(array $settings): ?string
    {
        $parts = explode(',', $this->text($settings, 'caption_languages') ?? '');
        $language = trim($parts[0]);

        return $language === '' ? null : $language;
    }

    /** @param array<string, mixed> $settings */
    private function captionsEnabled(array $settings): bool
    {
        return ($settings['captions'] ?? 'off') !== 'off';
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

    /** @param array<string, mixed> $settings */
    private function text(array $settings, string $key): ?string
    {
        return isset($settings[$key]) && is_string($settings[$key]) ? $settings[$key] : null;
    }

    /** @param array<string, mixed> $settings */
    private function bool(array $settings, string $key): bool
    {
        return filter_var($settings[$key] ?? false, FILTER_VALIDATE_BOOL) === true;
    }

    /** @param list<mixed> $arguments */
    private function call(object $target, string $method, array $arguments): mixed
    {
        return $target->{$method}(...$arguments);
    }
}
