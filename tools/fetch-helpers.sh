#!/bin/sh
set -eu
test -n "${FFMPEG_PATH:-}" || { echo 'set FFMPEG_PATH to a pinned, verified ffmpeg payload' >&2; exit 1; }
out=${PLUGIN_HELPERS_DIR:?set PLUGIN_HELPERS_DIR}/ffmpeg
mkdir -p "$(dirname "$out")"
cp "$FFMPEG_PATH" "$out"
chmod 0555 "$out"
