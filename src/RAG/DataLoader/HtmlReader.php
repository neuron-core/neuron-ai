<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use Html2Text\Html2Text;

class HtmlReader implements ReaderInterface
{
    /**
     * Return the Markdown version of a web page content.
     */
    public static function getText(string $filePath, array $options = []): string
    {
        $html = new Html2Text(\file_get_contents($filePath));

        return $html->getText();
    }
}
