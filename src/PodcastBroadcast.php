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
        $xml = (new PodcastFeedBuilder())->build($request, $settings);
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

}
