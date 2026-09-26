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
use function sort;

class FileDataLoaderSymlinkEscapeTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_file_loader_escape');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_symlinks_pointing_outside_the_loaded_directory_are_not_followed(): void
    {
        $outside = $this->createSandbox('neuron_file_loader_outside');
        file_put_contents($outside . '/secret.txt', 'Outside secret');
        mkdir($this->sandbox . '/docs');
        file_put_contents($this->sandbox . '/docs/guide.txt', 'Guide');
        $this->symlinkOrSkip($outside . '/secret.txt', $this->sandbox . '/docs/escape.txt');

        try {
            $contents = array_map(static fn (Document $document): string => $document->getContent(), FileDataLoader::for($this->sandbox . '/docs')->getDocuments());
        } finally {
            $this->removeSandbox($outside);
        }

        $this->assertSame(['Guide'], $contents);
    }

    public function test_symlinks_inside_the_loaded_directory_are_still_followed(): void
    {
        mkdir($this->sandbox . '/docs');
        mkdir($this->sandbox . '/docs/shared');
        file_put_contents($this->sandbox . '/docs/shared/guide.txt', 'Guide');
        $this->symlinkOrSkip($this->sandbox . '/docs/shared/guide.txt', $this->sandbox . '/docs/alias.txt');

        $contents = array_map(static fn (Document $document): string => $document->getContent(), FileDataLoader::for($this->sandbox . '/docs')->getDocuments());
        sort($contents);

        $this->assertSame(['Guide', 'Guide'], $contents);
    }

    public function test_a_directory_symlink_cycle_does_not_recurse_forever(): void
    {
        mkdir($this->sandbox . '/docs');
        file_put_contents($this->sandbox . '/docs/guide.txt', 'Guide');
        $this->symlinkOrSkip($this->sandbox . '/docs', $this->sandbox . '/docs/loop');

        $contents = array_map(static fn (Document $document): string => $document->getContent(), FileDataLoader::for($this->sandbox . '/docs')->getDocuments());

        $this->assertSame(['Guide'], $contents);
    }
}
