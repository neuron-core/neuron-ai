<?php

declare(strict_types=1);

namespace NeuronAI\RAG\DataLoader;

use Html2Text\Html2Text;
use NeuronAI\Exceptions\DataReaderException;

class HtmlReader implements ReaderInterface
{
    /**
     * Return the Markdown version of a web page content.
     *
     * @throws DataReaderException
     */
    public function read(string $filePath): string
    {
        $html = new Html2Text((new TextFileReader())->read($filePath));

        return $html->getText();
    }
}
