<?php

declare(strict_types=1);

namespace Podcast;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Stashd\PluginSdk\BroadcastPlugin;
use Stashd\PluginSdk\DerivedArtifact;
use Stashd\PluginSdk\FinalizationRequest;
use Stashd\PluginSdk\Item;
use Stashd\PluginSdk\ItemResource;
use Stashd\PluginSdk\OperationRequest;
use Stashd\PluginSdk\OperationResult;
use Stashd\PluginSdk\PluginContext;
use Stashd\PluginSdk\Preparation;
use Stashd\PluginSdk\Publication;
use Stashd\PluginSdk\PublishRequest;
use Stashd\PluginSdk\Setting;
use Stashd\PluginSdk\StagingArea;
use XMLWriter;

final class PodcastBroadcast implements BroadcastPlugin
{
    private const AUDIO_DERIVATION = 'podcast-audio-v1';

    private const ITUNES_NS = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

    private const ATOM_NS = 'http://www.w3.org/2005/Atom';

    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    private const PODCAST_NS = 'https://podcastindex.org/namespace/1.0';

    public function prepare(PublishRequest $request): Preparation
    {
        if ($this->setting($request, 'media_kind', 'audio') !== 'audio') {
            return new Preparation();
        }
        $artifacts = [];

        $total = count($request->items);

        foreach ($request->items as $index => $item) {
            $request->progress?->report(sprintf('Preparing item %d of %d: %s', $index + 1, $total, $item->title), $total > 0 ? $index / $total * 0.5 : 0.0);

            if ($this->audioResource($item) !== null) {
                $request->progress?->report(sprintf('Prepared item %d of %d: %s', $index + 1, $total, $item->title), $total > 0 ? ($index + 1) / $total * 0.5 : 0.5);

                continue;
            }
            $video = $this->resource($item, 'video');

            if ($video === null) {
                $request->progress?->report(sprintf('Prepared item %d of %d: %s', $index + 1, $total, $item->title), $total > 0 ? ($index + 1) / $total * 0.5 : 0.5);

                continue;
            }

            if ($request->staging === null || $request->helpers === null) {
                throw new RuntimeException('Podcast audio preparation requires staging and the ffmpeg helper.');
            }

            $name = 'derived-' . $this->safeId($item->id) . '.mp3';
            $result = $request->helpers->run('ffmpeg', [
                '-nostdin', '-y', '-i', '/staging/' . $video->reference,
                '-vn', '-map_metadata', '0', '-map_chapters', '0',
                '-codec:a', 'libmp3lame', '-b:a', '128k', '-ac', '2', '-ar', '44100',
                '/staging/' . $name,
            ]);

            if ($result->exitCode !== 0) {
                throw new RuntimeException('Podcast audio helper failed: ' . trim($result->stderr));
            }

            $staged = $request->staging->stage($name, 'audio/mpeg');
            $artifacts[] = new DerivedArtifact($item->id, $name, $video->reference, self::AUDIO_DERIVATION, 'audio', 'audio/mpeg', $staged->sizeBytes);
            $request->progress?->report(sprintf('Prepared item %d of %d: %s', $index + 1, $total, $item->title), $total > 0 ? ($index + 1) / $total * 0.5 : 0.5);
        }

        return new Preparation($artifacts);
    }

    public function publish(PublishRequest $request): Publication
    {
        if ($request->staging === null) {
            throw new RuntimeException('Podcast publication requires staging.');
        }

        $settings = $this->settings($request);
        $xml = $this->feed($request, $settings);
        $artifact = $request->staging->write('feed.xml', $xml, 'application/rss+xml');

        return new Publication(
            new \Stashd\PluginSdk\Artifact($artifact->reference, $artifact->mediaType, $artifact->sizeBytes),
            publishedMetadata: $this->text($settings, 'publication_url') === null ? [] : [new Setting('publication_url', \Stashd\PluginSdk\OptionValue::text($this->text($settings, 'publication_url')))],
        );
    }

    public function finalize(FinalizationRequest $request, PluginContext $context): Publication
    {
        return $request->publication;
    }

    public function operation(OperationRequest $request, PluginContext $context): OperationResult
    {
        throw new RuntimeException('Podcast does not support operations.');
    }

    /** @param array<string, mixed> $settings */
    private function feed(PublishRequest $request, array $settings): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('rss');
        $writer->writeAttribute('version', '2.0');

        foreach (['itunes' => self::ITUNES_NS, 'atom' => self::ATOM_NS, 'content' => self::CONTENT_NS, 'podcast' => self::PODCAST_NS] as $prefix => $uri) {
            $writer->writeAttribute('xmlns:' . $prefix, $uri);
        }
        $writer->startElement('channel');
        $this->element($writer, 'title', $this->text($settings, 'title') ?? 'Stashd Podcast');
        $this->element($writer, 'description', $this->text($settings, 'description') ?? '');
        $this->element($writer, 'language', $this->text($settings, 'language') ?? 'en');
        $publicationUrl = $this->text($settings, 'publication_url');

        if ($publicationUrl !== null && $publicationUrl !== '') {
            $writer->startElement('atom:link');
            $writer->writeAttribute('rel', 'self');
            $writer->writeAttribute('href', $publicationUrl);
            $writer->endElement();

            if (($link = $this->text($settings, 'link_url')) !== null && $link !== '') {
                $this->element($writer, 'link', $link);
            }
        }
        $this->elementNs($writer, 'itunes', 'summary', $this->text($settings, 'description') ?? '');
        $this->elementNs($writer, 'itunes', 'block', 'No');
        $this->elementNs($writer, 'itunes', 'complete', $this->bool($settings, 'complete') ? 'Yes' : 'No');
        $this->elementNs($writer, 'itunes', 'author', $this->text($settings, 'author') ?? '');
        $this->elementNs($writer, 'itunes', 'explicit', $this->bool($settings, 'explicit') ? 'true' : 'false');

        if (($image = $this->text($settings, 'image_url')) !== null && $image !== '') {
            $writer->startElement('itunes:image');
            $writer->writeAttribute('href', $image);
            $writer->endElement();
        }
        $this->elementNs($writer, 'podcast', 'medium', $this->text($settings, 'media_kind') === 'video' ? 'video' : 'podcast');
        $guid = $this->text($settings, 'podcast_guid') ?? $this->text($settings, 'guid');

        if ($guid !== null && $guid !== '') {
            $this->elementNs($writer, 'podcast', 'guid', $guid);
        }

        if (($funding = $this->text($settings, 'funding_url')) !== null && $funding !== '') {
            $writer->startElement('podcast:funding');
            $writer->writeAttribute('url', $funding);
            $writer->text($funding);
            $writer->endElement();
        }

        $total = count($request->items);
        $publishOffset = $this->setting($request, 'media_kind', 'audio') === 'audio' ? 0.5 : 0.0;

        foreach ($request->items as $index => $item) {
            $request->progress?->report(sprintf('Publishing item %d of %d: %s', $index + 1, $total, $item->title), $publishOffset + ($total > 0 ? $index / $total * (1.0 - $publishOffset) : 0.0));
            $resource = $this->selectedResource($item, $settings);

            if ($resource === null || $resource->url === null || $resource->url === '') {
                continue;
            }
            $writer->startElement('item');
            $this->element($writer, 'guid', $item->id);
            $this->element($writer, 'title', $item->title);
            $this->cdataElement($writer, 'description', $item->description ?? '');
            $this->cdataElementNs($writer, 'content', 'encoded', $item->description ?? '');
            $this->element($writer, 'pubDate', $this->date($item->publishedAt));
            $writer->startElement('enclosure');
            $writer->writeAttribute('url', $resource->url);
            $writer->writeAttribute('length', (string) $resource->sizeBytes);
            $writer->writeAttribute('type', $resource->mediaType ?? ($settings['media_kind'] === 'video' ? 'video/mp4' : 'audio/mpeg'));
            $writer->endElement();

            if ($item->durationSeconds !== null) {
                $this->elementNs($writer, 'itunes', 'duration', $this->duration($item->durationSeconds));
            }

            $itemImage = $this->resource($item, 'image');

            if ($itemImage !== null && $itemImage->url !== null) {
                $writer->startElement('itunes:image');
                $writer->writeAttribute('href', $itemImage->url);
                $writer->endElement();
            }

            if ($this->captionsEnabled($settings)) {
                $transcript = $this->transcript($item, $settings);

                if ($transcript?->url !== null) {
                    $writer->startElement('podcast:transcript');
                    $writer->writeAttribute('url', $transcript->url);
                    $writer->writeAttribute('type', $transcript->mediaType ?? 'text/vtt');
                    $writer->endElement();
                }
            }
            $writer->endElement();
            $request->progress?->report(sprintf('Published item %d of %d: %s', $index + 1, $total, $item->title), $publishOffset + ($total > 0 ? ($index + 1) / $total * (1.0 - $publishOffset) : 1.0 - $publishOffset));
        }
        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /** @param array<string, mixed> $settings */
    private function selectedResource(Item $item, array $settings): ?ItemResource
    {
        return $settings['media_kind'] === 'video' ? $this->resource($item, 'video') : $this->audioResource($item);
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
        $captionLanguages = $settings['caption_languages'] ?? 'en';
        $languages = array_filter(array_map('trim', explode(',', is_string($captionLanguages) ? $captionLanguages : 'en')));

        foreach ($item->resources as $resource) {
            if ($resource->kind !== 'subtitle') {
                continue;
            }

            if ($languages === [] || $resource->reference === '' || str_contains(strtolower($resource->reference), strtolower((string) $languages[0]))) {
                return $resource;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $settings */
    private function captionsEnabled(array $settings): bool
    {
        return ($settings['captions'] ?? 'off') !== 'off';
    }

    private function date(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return (new DateTimeImmutable())->format(DateTimeInterface::RFC2822);
        }

        try {
            return (new DateTimeImmutable($value))->format(DateTimeInterface::RFC2822);
        } catch (\Throwable) {
            return (new DateTimeImmutable())->format(DateTimeInterface::RFC2822);
        }
    }

    private function duration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function safeId(string $id): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $id), '_') ?: 'item';
    }

    /** @return array<string, mixed> */
    private function settings(PublishRequest $request): array
    {
        $settings = ['media_kind' => 'audio', 'captions' => 'off', 'caption_languages' => 'en', 'complete' => false, 'explicit' => false];

        foreach ($request->settings as $setting) {
            $settings[$setting->key] = $setting->value->value;
        }

        return $settings;
    }

    private function setting(PublishRequest $request, string $key, string $default): string
    {
        foreach ($request->settings as $setting) {
            if ($setting->key === $key) {
                return (string) $setting->value->value;
            }
        }

        return $default;
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

    private function element(XMLWriter $writer, string $name, string $value): void
    {
        $writer->writeElement($name, $value);
    }

    private function elementNs(XMLWriter $writer, string $prefix, string $name, string $value): void
    {
        $writer->writeElement($prefix . ':' . $name, $value);
    }

    private function cdataElement(XMLWriter $writer, string $name, string $value): void
    {
        $writer->startElement($name);
        $this->cdata($writer, $value);
        $writer->endElement();
    }

    private function cdataElementNs(XMLWriter $writer, string $prefix, string $name, string $value): void
    {
        $writer->startElement($prefix . ':' . $name);
        $this->cdata($writer, $value);
        $writer->endElement();
    }

    private function cdata(XMLWriter $writer, string $value): void
    {
        $writer->writeRaw('<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>');
    }
}
