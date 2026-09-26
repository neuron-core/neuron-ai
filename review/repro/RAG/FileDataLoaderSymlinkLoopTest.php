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

class FileDataLoaderSymlinkLoopTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_file_loader_loop');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_a_symlink_loop_loads_every_file_once(): void
    {
        mkdir($this->sandbox . '/docs');
        file_put_contents($this->sandbox . '/docs/a.txt', 'Only once');
        $this->symlinkOrSkip($this->sandbox, $this->sandbox . '/docs/loop');

        $documents = FileDataLoader::for($this->sandbox)->getDocuments();

        $this->assertSame(['Only once'], array_map(static fn (Document $document): string => $document->getContent(), $documents));
    }
}
