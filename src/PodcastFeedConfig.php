<?php

declare(strict_types=1);

namespace Podcast;

use Stashd\PluginSdk\PublishRequest;
use Uri\Rfc3986\Uri;

final readonly class PodcastFeedConfig
{
    /**
     * @param list<string> $categories
     * @param list<string> $captionLanguages
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $language,
        public ?string $author,
        public bool $explicit,
        public bool $complete,
        public array $categories,
        public string $podcastType,
        public ?string $podcastGuid,
        public string $mediaKind,
        public string $captions,
        public array $captionLanguages,
        public ?Uri $fundingUrl,
        public string $fundingLabel,
        public ?Uri $linkUrl,
        public ?Uri $publicationUrl,
        public ?Uri $imageUrl,
    ) {}

    public static function fromRequest(PublishRequest $request): self
    {
        $values = [
            'media_kind' => 'audio',
            'captions' => 'off',
            'caption_languages' => 'en',
            'complete' => false,
            'explicit' => false,
        ];

        foreach ($request->settings as $setting) {
            $values[$setting->key] = $setting->value->value;
        }

        $title = self::text($values, 'title')
            ?? self::text($values, 'broadcast_name')
            ?? self::text($values, 'stash_name')
            ?? throw new \InvalidArgumentException('Podcast title is required.');

        return new self(
            title: $title,
            description: self::text($values, 'description') ?? self::text($values, 'stash_description') ?? $title,
            language: self::text($values, 'language') ?? 'en',
            author: self::text($values, 'author'),
            explicit: self::bool($values, 'explicit'),
            complete: self::bool($values, 'complete'),
            categories: self::csv($values, 'categories'),
            podcastType: in_array(self::text($values, 'podcast_type'), ['episodic', 'serial'], true) ? (string) $values['podcast_type'] : 'episodic',
            podcastGuid: self::text($values, 'podcast_guid') ?? self::text($values, 'guid'),
            mediaKind: self::text($values, 'media_kind') === 'video' ? 'video' : 'audio',
            captions: self::text($values, 'captions') ?? 'off',
            captionLanguages: self::csv($values, 'caption_languages'),
            fundingUrl: self::url(self::text($values, 'funding_url')) ?? self::fundingUrl(self::text($values, 'description')),
            fundingLabel: self::text($values, 'funding_label') ?: 'Support this podcast',
            linkUrl: self::url(self::text($values, 'link_url')),
            publicationUrl: self::url(self::text($values, 'publication_url')),
            imageUrl: self::url(self::text($values, 'image_url') ?? self::text($values, 'stash_icon_uri')),
        );
    }

    /** @param array<string, mixed> $values */
    private static function text(array $values, string $key): ?string
    {
        return isset($values[$key]) && is_string($values[$key]) && trim($values[$key]) !== '' ? trim($values[$key]) : null;
    }

    /** @param array<string, mixed> $values
     * @return list<string>
     */
    private static function csv(array $values, string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', self::text($values, $key) ?? ''))));
    }

    /** @param array<string, mixed> $values */
    private static function bool(array $values, string $key): bool
    {
        return filter_var($values[$key] ?? false, FILTER_VALIDATE_BOOL) === true;
    }

    private static function fundingUrl(?string $description): ?Uri
    {
        if ($description === null) {
            return null;
        }

        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match('~https?://(?:www\.)?(?:patreon\.com|ko-fi\.com|buymeacoffee\.com|paypal\.me|paypal\.com/(?:donate|payments)|liberapay\.com|github\.com/sponsors|opencollective\.com)(?:/[^\s<]*)?~i', $description, $match);

        return isset($match[0]) ? self::url(rtrim($match[0], ".,;:!?)]}'\"")) : null;
    }

    private static function url(?string $value): ?Uri
    {
        if ($value === null) {
            return null;
        }

        try {
            $url = Uri::parse($value);
        } catch (\Throwable) {
            return null;
        }

        if ($url === null) {
            return null;
        }

        return in_array(strtolower($url->getScheme() ?? ''), ['http', 'https'], true) && $url->getHost() !== null ? $url : null;
    }
}
