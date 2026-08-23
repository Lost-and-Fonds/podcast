# Stashd Podcast plugin

Podcast Broadcast plugin for Stashd. It publishes RSS 2.0 feeds with iTunes,
Atom, content, and Podcasting 2.0 metadata from Vault media.

Audio mode prefers original audio and derives MP3 from video through the
declared package helper when needed. Video mode publishes the video resource
directly. Captions can be published as Podcasting 2.0 transcript entries, and
`funding_url` is emitted as `podcast:funding` when configured.

Install with Composer:

```sh
composer require stashd/podcast
```

The package requires PHP 8.5, `stashd/plugin-sdk`, and FFmpeg available to the
plugin runtime through the declared helper. Run `composer test` for local
provider tests. The core application owns persistence, publication, activation,
and lifecycle orchestration.

## Release artifact

`stashd-plugin/helpers.lock.json` pins the GPL FFmpeg payload for linux/amd64
and linux/arm64. Core verifies and materializes that payload; host PATH is not
used.
