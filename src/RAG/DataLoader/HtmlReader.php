<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use Html2Text\Html2Text;

use function file_get_contents;

class HtmlReader implements ReaderInterface
{
    /**
     * Return the Markdown version of a web page content.
     */
    public function read(string $filePath): string
    {
        $html = new Html2Text(file_get_contents($filePath));

        return $html->getText();
    }
}
