<?php

declare(strict_types=1);

use Podcast\PodcastBroadcast;
use Stashd\PluginSdk as Sdk;

spl_autoload_register(static function (string $class): void {
    foreach ([
        'Podcast\\' => dirname(__DIR__, 2) . '/src/',
        'Stashd\\PluginSdk\\' => dirname(__DIR__, 3) . '/plugin-sdk/src/',
    ] as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = $root . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        }
    }
    expect(true)->toBeTrue();
});

it('preserves the Podcast provider contract', function (): void {
    function podcastAssert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    final class PodcastStaging implements Sdk\StagingArea
    {
        /** @var array<string, string> */
        public array $files = [];

        public function write(string $relativePath, string $content, ?string $mediaType = null): Sdk\StagedArtifact
        {
            $this->files[$relativePath] = $content;

            return new Sdk\StagedArtifact('staging:' . $relativePath, $mediaType ?? 'application/octet-stream', strlen($content));
        }

        public function stage(string $relativePath, ?string $mediaType = null): Sdk\StagedArtifact
        {
            return new Sdk\StagedArtifact('staging:' . $relativePath, $mediaType ?? 'application/octet-stream', 42);
        }
    }

    final class PodcastHelper implements Sdk\HelperRunner
    {
        /** @var list<string> */
        public array $arguments = [];

        public function run(string $name, array $arguments = [], ?callable $onOutput = null): Sdk\HelperResult
        {
            podcastAssert($name === 'ffmpeg', 'unexpected helper name');
            $this->arguments = $arguments;

            return new Sdk\HelperResult(0);
        }
    }

    final class PodcastProgress implements Sdk\ProgressReporter
    {
        /** @var list<array{stage: string, fraction: ?float}> */
        public array $events = [];

        public function report(string $stage, ?float $fraction = null): void
        {
            $this->events[] = ['stage' => $stage, 'fraction' => $fraction];
        }
    }

    $plugin = new PodcastBroadcast();
    $staging = new PodcastStaging();
    $helper = new PodcastHelper();
    $progress = new PodcastProgress();
    $video = new Sdk\ItemResource('resources/video.mp4', 'video', url: 'https://media.test/video.mp4', mediaType: 'video/mp4', sizeBytes: 100);
    $image = new Sdk\ItemResource('thumbnail.jpg', 'image', url: 'https://media.test/assets/thumbnail/episode-1', mediaType: 'image/jpeg');
    $item = new Sdk\Item('episode-1', 'A <title>', [$video], description: 'A description with ]]> safely embedded', publishedAt: '2026-08-23T12:34:56+00:00', durationSeconds: 3723);
    $request = new Sdk\PublishRequest('broadcast-1', [
        new Sdk\Setting('title', Sdk\OptionValue::text('My Podcast')),
        new Sdk\Setting('description', Sdk\OptionValue::text('A feed')),
        new Sdk\Setting('author', Sdk\OptionValue::text('Author')),
        new Sdk\Setting('publication_url', Sdk\OptionValue::text('https://media.test/feed.xml')),
        new Sdk\Setting('captions', Sdk\OptionValue::text('creator_only')),
        new Sdk\Setting('categories', Sdk\OptionValue::text('Technology, History')),
        new Sdk\Setting('podcast_guid', Sdk\OptionValue::text('podcast-guid-1')),
        new Sdk\Setting('funding_url', Sdk\OptionValue::text('https://media.test/support')),
        new Sdk\Setting('funding_label', Sdk\OptionValue::text('Support the archive')),
    ], [], [$item], $staging, $helper, $progress);

    $preparation = $plugin->prepare($request);
    podcastAssert(count($preparation->artifacts) === 1, 'video-only audio item was not prepared');
    podcastAssert($preparation->artifacts[0]->derivationKey === 'podcast-audio-v1', 'derivation key changed');
    podcastAssert(in_array('/staging/resources/video.mp4', $helper->arguments, true), 'helper input was not staged');
    podcastAssert(in_array('/staging/derived-episode-1.mp3', $helper->arguments, true), 'helper output was not staged');
    podcastAssert($progress->events[0]['fraction'] === 0.0 && $progress->events[1]['fraction'] === 0.5, 'item progress was not reported');

    $publishedItem = new Sdk\Item('episode-1', 'A <title>', [$video, $image, new Sdk\ItemResource('derived-episode-1.mp3', 'audio', 'podcast-audio-v1', 'https://media.test/episode-1.mp3', 'audio/mpeg', 42), new Sdk\ItemResource('captions-en.vtt', 'subtitle', url: 'https://media.test/episode-1.vtt', mediaType: 'text/vtt')], description: 'A description with ]]> safely embedded', publishedAt: '2026-08-23T12:34:56+00:00', durationSeconds: 3723);
    $publication = $plugin->publish(new Sdk\PublishRequest('broadcast-1', $request->settings, [], [$publishedItem], $staging, $helper));
    $xml = $staging->files['feed.xml'] ?? '';
    $parsed = simplexml_load_string($xml);
    podcastAssert($parsed !== false, 'feed XML is not well formed');
    podcastAssert(isset($parsed->channel->item->enclosure), 'feed item enclosure is missing');
    podcastAssert((string) $parsed->channel->item->title === 'A <title>', 'XML escaping changed title');
    podcastAssert((string) $parsed->channel->item->enclosure['url'] === 'https://media.test/episode-1.mp3', 'derived audio URL was not selected');
    podcastAssert((string) $parsed->channel->item->pubDate === 'Sun, 23 Aug 2026 12:34:56 +0000', 'publication date formatting changed');
    podcastAssert(str_contains($xml, 'href="https://media.test/assets/thumbnail/episode-1"'), 'routed item artwork was not published');
    podcastAssert(str_contains($xml, '<![CDATA[A description with ]]]]><![CDATA[> safely embedded]]>'), 'CDATA terminator was not split safely');
    podcastAssert(str_contains($xml, 'xmlns:podcast='), 'Podcast namespace is missing');
    podcastAssert(str_contains($xml, 'url="https://media.test/episode-1.vtt"'), 'transcript URL was not published');
    $parsed->registerXPathNamespace('itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd');
    $parsed->registerXPathNamespace('podcast', 'https://podcastindex.org/namespace/1.0');
    $categories = $parsed->xpath('/rss/channel/itunes:category') ?: [];
    podcastAssert((string) ($categories[0]['text'] ?? '') === 'Technology', 'first category is missing');
    podcastAssert((string) ($categories[1]['text'] ?? '') === 'History', 'second category is missing');
    podcastAssert(count($parsed->xpath('/rss/channel/itunes:complete') ?: []) === 0, 'ongoing feed incorrectly claims completion');
    podcastAssert(count($parsed->xpath('/rss/channel/itunes:block') ?: []) === 0, 'feed emits an unnecessary block value');
    podcastAssert((string) (($parsed->xpath('/rss/channel/itunes:type') ?: [])[0] ?? '') === 'episodic', 'podcast type is missing');
    podcastAssert((string) (($parsed->xpath('/rss/channel/podcast:guid') ?: [])[0] ?? '') === 'podcast-guid-1', 'stable podcast GUID is missing');
    podcastAssert((string) (($parsed->xpath('/rss/channel/podcast:funding') ?: [])[0] ?? '') === 'Support the archive', 'funding metadata is incomplete');
    podcastAssert((string) ((($parsed->xpath('/rss/channel/itunes:image/@href') ?: [])[0] ?? '')) === 'https://media.test/assets/thumbnail/episode-1', 'routed feed artwork was not published');
    podcastAssert((string) ((($parsed->xpath('/rss/channel/item/itunes:duration') ?: [])[0] ?? '')) === '1:02:03', 'duration was not formatted correctly');
    podcastAssert($publication->artifact->mediaType === 'application/rss+xml', 'feed media type changed');

    $fallbackStaging = new PodcastStaging();
    $fallbackPublication = $plugin->publish(new Sdk\PublishRequest('broadcast-funding-fallback', [
        new Sdk\Setting('title', Sdk\OptionValue::text('Fallback Funding Podcast')),
        new Sdk\Setting('description', Sdk\OptionValue::text('Support the show at https://patreon.com/example.')),
    ], [], [$item], $fallbackStaging));
    $fallback = simplexml_load_string($fallbackStaging->files['feed.xml'] ?? '');
    podcastAssert($fallback !== false, 'funding fallback feed XML is invalid');
    $fallback->registerXPathNamespace('podcast', 'https://podcastindex.org/namespace/1.0');
    podcastAssert((string) (($fallback->xpath('/rss/channel/podcast:funding/@url') ?: [])[0] ?? '') === 'https://patreon.com/example', 'funding URL was not detected from the description');
    podcastAssert($fallbackPublication->artifact->mediaType === 'application/rss+xml', 'funding fallback feed publication failed');

    $stashDefaults = \Podcast\PodcastFeedConfig::fromRequest(new Sdk\PublishRequest('stash-defaults', [
        new Sdk\Setting('broadcast_name', Sdk\OptionValue::text('Oculus Imperia Podcast')),
        new Sdk\Setting('stash_description', Sdk\OptionValue::text('Preserved episodes')),
    ]));
    podcastAssert($stashDefaults->title === 'Oculus Imperia Podcast' && $stashDefaults->description === 'Preserved episodes', 'stash metadata was not used for podcast defaults');

    try {
        \Podcast\PodcastFeedConfig::fromRequest(new Sdk\PublishRequest('missing-title'));
        podcastAssert(false, 'missing podcast title was accepted');
    } catch (\InvalidArgumentException) {
        podcastAssert(true, 'missing podcast title was rejected');
    }

    $audio = new Sdk\ItemResource('audio.mp3', 'audio', url: 'https://media.test/audio.mp3', mediaType: 'audio/mpeg', sizeBytes: 12);
    $videoRequest = new Sdk\PublishRequest('broadcast-2', [new Sdk\Setting('media_kind', Sdk\OptionValue::text('video'))], [], [new Sdk\Item('episode-2', 'Video', [$video])], $staging);
    podcastAssert(count($plugin->prepare($videoRequest)->artifacts) === 0, 'video mode attempted derivation');
    $audioPublication = $plugin->publish(new Sdk\PublishRequest('broadcast-3', [new Sdk\Setting('title', Sdk\OptionValue::text('Audio Podcast'))], [], [new Sdk\Item('episode-3', 'Audio', [$audio, $video])], $staging));
    podcastAssert($audioPublication->artifact->mediaType === 'application/rss+xml', 'audio feed publication failed');

    $completeStaging = new PodcastStaging();
    $completePublication = $plugin->publish(new Sdk\PublishRequest('broadcast-4', [
        new Sdk\Setting('title', Sdk\OptionValue::text('Finished Podcast')),
        new Sdk\Setting('complete', Sdk\OptionValue::boolean(true)),
        new Sdk\Setting('media_kind', Sdk\OptionValue::text('video')),
    ], [], [new Sdk\Item('episode-4', 'Video', [$video])], $completeStaging));
    $completeXml = $completeStaging->files['feed.xml'] ?? '';
    $complete = simplexml_load_string($completeXml);
    podcastAssert($complete !== false, 'completed feed XML is invalid');
    podcastAssert((string) $complete->channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd')->complete === 'Yes', 'completed feed does not advertise completion');
    $complete->registerXPathNamespace('podcast', 'https://podcastindex.org/namespace/1.0');
    podcastAssert((string) (($complete->xpath('/rss/channel/podcast:medium') ?: [])[0] ?? '') === 'video', 'video medium is missing');
    podcastAssert($completePublication->artifact->mediaType === 'application/rss+xml', 'completed video feed publication failed');
    expect(true)->toBeTrue();
});
