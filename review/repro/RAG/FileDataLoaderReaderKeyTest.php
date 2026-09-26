<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\Tests\RAG\DataLoader\Stub\FileNameReader;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

class FileDataLoaderReaderKeyTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_file_loader_reader_key');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_reader_extensions_are_registered_case_insensitively(): void
    {
        $path = $this->sandbox . '/guide.md';
        file_put_contents($path, '# ignored');

        $documents = FileDataLoader::for($path)->addReader('MD', new FileNameReader())->getDocuments();

        $this->assertSame('read by FileNameReader: guide.md', $documents[0]->getContent());
    }

    public function test_readers_passed_to_the_constructor_are_registered_case_insensitively(): void
    {
        $path = $this->sandbox . '/guide.md';
        file_put_contents($path, '# ignored');

        $documents = (new FileDataLoader($path, ['MD' => new FileNameReader()]))->getDocuments();

        $this->assertSame('read by FileNameReader: guide.md', $documents[0]->getContent());
    }
}
