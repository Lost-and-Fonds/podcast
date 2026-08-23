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
plugin runtime through the package helper. Run `./tests/run.sh` for local
provider tests. The core application owns persistence, publication, activation,
and lifecycle orchestration.

## Release artifact

Run `tools/build-oci.sh out/plugin.oci` after installing production dependencies. The output is an OCI image layout; helper-bearing plugins require pinned executable payloads through `PLUGIN_HELPERS_DIR`.

Helper release inputs are deliberate: set `PLUGIN_HELPERS_DIR` to a directory containing the pinned, checksum-verified helper executables before running `tools/build-oci.sh`. The build refuses missing helpers and never uses host PATH.
