<?php

declare(strict_types=1);

namespace NeuronAI;

trait HandleContent
{
    /**
     * Remove content between delimiters.
     */
    protected function removeDelimitedContent(string $text, string $openTag, string $closeTag): string
    {
        $escapedOpenTag = \preg_quote($openTag, '/');
        $escapedCloseTag = \preg_quote($closeTag, '/');
        $pattern = '/' . $escapedOpenTag . '.*?' . $escapedCloseTag . '/s';

        return \preg_replace($pattern, '', $text);
    }
}
