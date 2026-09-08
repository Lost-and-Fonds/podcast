<?php

declare(strict_types=1);

namespace Podcast;

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

final class PodcastBroadcast implements BroadcastPlugin
{
    private const AUDIO_DERIVATION = 'podcast-audio-v1';

    private const TRANSCRIPT_DERIVATION = 'podcast-transcript-v1';

    public function prepare(PublishRequest $request, PluginContext $context): Preparation
    {
        $artifacts = [];
        $total = count($request->items);
        $captions = $this->setting($request, 'captions', 'off');
        $captionLanguage = $this->captionLanguage($request);
        $mediaKind = $this->setting($request, 'media_kind', 'audio');

        foreach ($request->items as $index => $item) {
            $context->progress->report(sprintf('Preparing media · %d of %d', $index, $total), $total > 0 ? $index / $total * 0.5 : 0.0);

            $this->prepareTranscript($item, $captions, $captionLanguage, $context, $artifacts);

            if ($mediaKind !== 'audio') {
                $context->progress->report(sprintf('Preparing media · %d of %d', $index + 1, $total), $total > 0 ? ($index + 1) / $total * 0.5 : 0.5);

                continue;
            }

            if ($this->audioResource($item) !== null) {
                $context->progress->report(sprintf('Preparing media · %d of %d', $index + 1, $total), $total > 0 ? ($index + 1) / $total * 0.5 : 0.5);

                continue;
            }
            $video = $this->resource($item, 'video');

            if ($video === null) {
                $context->progress->report(sprintf('Preparing media · %d of %d', $index + 1, $total), $total > 0 ? ($index + 1) / $total * 0.5 : 0.5);

                continue;
            }

            if ($context->staging === null || $context->helpers === null) {
                throw new RuntimeException('Podcast audio preparation requires staging and the ffmpeg helper.');
            }

            $name = 'derived-' . $this->safeId($item->id) . '.mp3';
            $result = $context->helpers->run('ffmpeg', [
                '-nostdin', '-y', '-i', '/staging/' . $video->reference,
                '-vn', '-map_metadata', '0', '-map_chapters', '0',
                '-codec:a', 'libmp3lame', '-b:a', '128k', '-ac', '2', '-ar', '44100',
                '/staging/' . $name,
            ]);

            if ($result->exitCode !== 0) {
                throw new RuntimeException('Podcast audio helper failed: ' . trim($result->stderr));
            }

            $staged = $context->staging->stage($name, 'audio/mpeg');
            $artifacts[] = new DerivedArtifact($item->id, $name, $video->reference, self::AUDIO_DERIVATION, 'audio', 'audio/mpeg', $staged->sizeBytes);
            $context->progress->report(sprintf('Preparing media · %d of %d', $index + 1, $total), $total > 0 ? ($index + 1) / $total * 0.5 : 0.5);
        }

        return new Preparation($artifacts);
    }

    public function publish(PublishRequest $request, PluginContext $context): Publication
    {
        if ($context->staging === null) {
            throw new RuntimeException('Podcast publication requires staging.');
        }

        $config = PodcastFeedConfig::fromRequest($request);
        $xml = (new PodcastFeedBuilder())->build($request, $config, $context->progress);
        $artifact = $context->staging->write('feed.xml', $xml, 'application/rss+xml');

        return new Publication(
            new \Stashd\PluginSdk\Artifact($artifact->reference, $artifact->mediaType, $artifact->sizeBytes),
            publishedMetadata: $config->publicationUrl === null ? [] : [new Setting('publication_url', \Stashd\PluginSdk\OptionValue::text($config->publicationUrl->toString()))],
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

    /** @param list<DerivedArtifact> $artifacts */
    private function prepareTranscript(Item $item, string $captions, string $captionLanguage, PluginContext $context, array &$artifacts): void
    {
        if ($captions === 'off' || $context->staging === null) {
            return;
        }

        $subtitle = $this->subtitleResource($item, $captionLanguage);

        if ($subtitle === null) {
            return;
        }

        $captions = @file_get_contents('/staging/' . $subtitle->reference);

        if (! is_string($captions)) {
            return;
        }

        $transcript = (new PodcastTranscriptFormatter())->format($captions);

        if ($transcript === '') {
            return;
        }

        $name = 'transcript-' . $this->safeId($item->id) . '.txt';
        $staged = $context->staging->write($name, $transcript, 'text/plain');
        $artifacts[] = new DerivedArtifact(
            $item->id,
            $name,
            $subtitle->reference,
            self::TRANSCRIPT_DERIVATION,
            'metadata',
            'text/plain',
            $staged->sizeBytes,
        );
    }

    private function subtitleResource(Item $item, string $captionLanguage): ?ItemResource
    {
        foreach ($item->resources as $resource) {
            if ($resource->kind === 'subtitle' && ($captionLanguage === '' || str_contains(strtolower($resource->reference), strtolower($captionLanguage)))) {
                return $resource;
            }
        }

        return null;
    }

    private function safeId(string $id): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $id), '_') ?: 'item';
    }

    private function captionLanguage(PublishRequest $request): string
    {
        return trim(explode(',', $this->setting($request, 'caption_languages', 'en'))[0]);
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

}
