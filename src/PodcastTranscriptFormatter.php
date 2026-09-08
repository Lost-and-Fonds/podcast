<?php

declare(strict_types=1);

namespace Podcast;

final class PodcastTranscriptFormatter
{
    public function format(string $captions): string
    {
        $captions = preg_replace('/^\xEF\xBB\xBF/', '', $captions) ?? $captions;
        $paragraphs = [];
        $lines = [];
        $skipBlock = false;

        foreach (preg_split('/\R/u', $captions) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                if ($lines !== []) {
                    $paragraphs[] = implode(' ', $lines);
                    $lines = [];
                }
                $skipBlock = false;

                continue;
            }

            if (str_starts_with($line, 'WEBVTT')) {
                continue;
            }

            if (preg_match('/^(?:NOTE|STYLE|REGION)(?:\s|$)/i', $line) === 1) {
                $skipBlock = true;

                continue;
            }

            if ($skipBlock || str_contains($line, '-->') || preg_match('/^\d+$/', $line) === 1) {
                continue;
            }

            $line = trim(html_entity_decode(preg_replace('/<[^>]*>/', '', $line) ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        if ($lines !== []) {
            $paragraphs[] = implode(' ', $lines);
        }

        return implode("\n\n", $paragraphs) . ($paragraphs === [] ? '' : "\n");
    }
}
