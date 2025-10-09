<?php

declare(strict_types=1);

namespace NeuronAI\StructuredOutput;

/**
 * Inspired by: https://github.com/cognesy/instructor-php
 */
class JsonExtractor
{
    protected array $extractors;

    public function __construct()
    {
        $this->extractors = [
            fn (string $text): array => [$text],                   // Try as it is
            fn (string $text): array => $this->findByMarkdown($text),
            fn (string $text): ?string => $this->findByBrackets($text),
            fn (string $text): array => $this->findJSONLikeStrings($text),
        ];
    }

    /**
     * Attempt to find and parse a complete valid JSON string in the input.
     * Returns a JSON-encoded string on success or an empty string on failure.
     */
    public function getJson(string $input): ?string
    {
        foreach ($this->extractors as $extractor) {
            $candidates = $extractor($input);
            if (empty($candidates)) {
                continue;
            }
            if (\is_string($candidates)) {
                $candidates = [$candidates];
            }

            foreach ($candidates as $candidate) {
                if (!\is_string($candidate)) {
                    continue;
                }
                if (\trim($candidate) === '') {
                    continue;
                }
                try {
                    $data = $this->tryParse($candidate);
                } catch (\Throwable) {
                    continue;
                }

                if ($data !== null) {
                    // Re-encode in canonical JSON form
                    $result = \json_encode($data);
                    if ($result !== false) {
                        return $result;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Returns an associative array on success, or null if the parsing fails.
     *
     * @throws \JsonException
     */
    private function tryParse(string $maybeJson): ?array
    {
        $data = \json_decode($maybeJson, true, 512, \JSON_THROW_ON_ERROR);

        if (\in_array($data, [false, null, ''], true)) {
            return null;
        }

        return $data;
    }

    /**
     * Find ALL fenced code blocks that start with ```json, and extract
     * the portion between the first '{' and the matching last '}' inside
     * that block. Return an array of candidates.
     */
    private function findByMarkdown(string $text): array
    {
        if (\trim($text) === '') {
            return [];
        }

        $candidates = [];
        $offset = 0;
        $fenceTag = '```json';

        while (($startFence = \strpos($text, $fenceTag, $offset)) !== false) {
            // Find the next triple-backtick fence AFTER the "```json"
            $closeFence = \strpos($text, '```', $startFence + \strlen($fenceTag));
            if ($closeFence === false) {
                // No closing fence found, stop scanning
                break;
            }

            // Substring that represents the code block between "```json" and "```"
            $codeBlock = \substr(
                $text,
                $startFence + \strlen($fenceTag),
                $closeFence - ($startFence + \strlen($fenceTag))
            );

            // Now find the first '{' and last '}' within this code block
            $firstBrace = \strpos($codeBlock, '{');
            $lastBrace = \strrpos($codeBlock, '}');
            if ($firstBrace !== false && $lastBrace !== false && $firstBrace < $lastBrace) {
                $jsonCandidate = \substr($codeBlock, $firstBrace, $lastBrace - $firstBrace + 1);
                $candidates[] = $jsonCandidate;
            }

            // Advance offset past the closing fence, so we can find subsequent code blocks
            $offset = $closeFence + 3; // skip '```'
        }

        return $candidates;
    }

    /**
     * Find a substring from the first '{' to the last '}'.
     */
    private function findByBrackets(string $text): ?string
    {
        $trimmed = \trim($text);
        if ($trimmed === '') {
            return null;
        }
        $firstOpen = \strpos($trimmed, '{');
        if ($firstOpen === 0 || $firstOpen === false) {
            return null;
        }

        $lastClose = \strrpos($trimmed, '}');
        if ($lastClose === false || $lastClose < $firstOpen) {
            return null;
        }

        return \substr($trimmed, $firstOpen, $lastClose - $firstOpen + 1);
    }

    /**
     * Scan through the text, capturing any substring that begins at '{'
     * and ends at its matching '}'—accounting for nested braces and strings.
     * Returns an array of all such candidates found.
     */
    private function findJSONLikeStrings(string $text): array
    {
        $text = \trim($text);
        if ($text === '') {
            return [];
        }

        $candidates = [];
        $currentCandidate = '';
        $bracketCount = 0;
        $inString = false;
        $escape = false;
        $len = \strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $char = $text[$i];

            if (!$inString) {
                if ($char === '{') {
                    if ($bracketCount === 0) {
                        $currentCandidate = '';
                    }
                    $bracketCount++;
                } elseif ($char === '}') {
                    $bracketCount--;
                }
            }

            // Toggle inString if we encounter an unescaped quote
            if ($char === '"' && !$escape) {
                $inString = !$inString;
            }

            // Determine if current char is a backslash for next iteration
            $escape = ($char === '\\' && !$escape);

            if ($bracketCount > 0) {
                $currentCandidate .= $char;
            }

            // If bracketCount just went back to 0, we’ve closed a JSON-like block
            if ($bracketCount === 0 && $currentCandidate !== '') {
                $currentCandidate .= $char; // include the closing '}'
                $candidates[] = $currentCandidate;
                $currentCandidate = '';
            }
        }

        return $candidates;
    }
}
