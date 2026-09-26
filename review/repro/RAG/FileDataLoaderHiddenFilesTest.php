<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\Document;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function mkdir;

class FileDataLoaderHiddenFilesTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_file_loader_hidden');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_hidden_files_are_not_ingested_from_a_directory(): void
    {
        file_put_contents($this->sandbox . '/.env', 'OPENAI_API_KEY=sk-secret');
        mkdir($this->sandbox . '/.git');
        file_put_contents($this->sandbox . '/.git/config', '[remote "origin"] url = https://token@example.test');
        file_put_contents($this->sandbox . '/guide.txt', 'Guide');

        $contents = array_map(static fn (Document $document): string => $document->getContent(), FileDataLoader::for($this->sandbox)->getDocuments());

        $this->assertSame(['Guide'], $contents);
    }

    public function test_an_explicitly_given_hidden_file_is_still_loaded(): void
    {
        file_put_contents($this->sandbox . '/.notes', 'Notes');

        $contents = array_map(static fn (Document $document): string => $document->getContent(), FileDataLoader::for($this->sandbox . '/.notes')->getDocuments());

        $this->assertSame(['Notes'], $contents);
    }
}
