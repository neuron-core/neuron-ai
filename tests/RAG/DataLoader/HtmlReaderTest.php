<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\HtmlReader;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function trim;

class HtmlReaderTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_html_reader');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_html_is_converted_to_readable_text_keeping_link_targets(): void
    {
        $path = $this->page('<h1>Città</h1><p>Fish &amp; chips at <a href="https://example.test/menu">the menu</a></p><ul><li>One</li></ul>');

        $this->assertSame(
            "CITTÀ\n\nFish & chips at the menu [https://example.test/menu]\n\n\t* One",
            trim(HtmlReader::getText($path))
        );
    }

    public function test_scripts_styles_and_head_are_not_part_of_the_text(): void
    {
        $path = $this->page(
            '<html><head><title>Hidden title</title><style>p { color: red }</style><script>steal(document.cookie)</script></head>'
            . '<body><p>Visible</p><script>var injected = true;</script></body></html>'
        );

        $this->assertSame('Visible', trim(HtmlReader::getText($path)));
    }

    public function test_file_data_loader_can_read_html_through_the_reader(): void
    {
        $path = $this->page('<p>Hello <strong>world</strong></p>');

        $documents = FileDataLoader::for($path)->addReader(['html', 'htm'], new HtmlReader())->getDocuments();

        $this->assertCount(1, $documents);
        $this->assertSame('Hello WORLD', trim($documents[0]->getContent()));
    }

    protected function page(string $html): string
    {
        $path = $this->sandbox . '/page.html';
        file_put_contents($path, $html);

        return $path;
    }
}
