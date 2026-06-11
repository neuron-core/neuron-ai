<?php

declare(strict_types=1);

namespace NeuronAI;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;

use function preg_quote;
use function preg_replace;
use function is_null;
use function is_string;
use function str_contains;

class ContentHelper
{
    /**
     * Remove content between delimiters.
     */
    public static function removeDelimitedContent(string $text, string $openTag, string $closeTag): string
    {
        $escapedOpenTag = preg_quote($openTag, '/');
        $escapedCloseTag = preg_quote($closeTag, '/');
        $pattern = '/' . $escapedOpenTag . '.*?' . $escapedCloseTag . '/s';

        return preg_replace($pattern, '', $text);
    }

    /**
     * @param string|SystemContent[]|null $instructions
     */
    public static function instructionsContainPrompt(string|array|null $instructions, string $prompt): bool
    {
        if (is_null($instructions)) {
            return false;
        }

        if (is_string($instructions)) {
            return str_contains($instructions, $prompt);
        }

        foreach ($instructions as $block) {
            if (str_contains($block->content, $prompt)) {
                return true;
            }
        }

        return false;
    }
}
