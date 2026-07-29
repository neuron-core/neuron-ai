<?php

declare(strict_types=1);

namespace NeuronAI;

use function preg_quote;
use function preg_replace;

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
}
