<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\ReaderInterface;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function file_put_contents;

class FileDataLoaderReadFailureTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_file_loader_failure');
        file_put_contents($this->sandbox . '/broken.pdf', 'not really a pdf');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_directory_mode_propagates_reader_failure(): void
    {
        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('extraction failed');

        FileDataLoader::for($this->sandbox, ['pdf' => new FailingReader()])->getDocuments();
    }

    public function test_single_file_mode_propagates_reader_failure_like_directory_mode(): void
    {
        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('extraction failed');

        FileDataLoader::for($this->sandbox . '/broken.pdf', ['pdf' => new FailingReader()])->getDocuments();
    }
}

class FailingReader implements ReaderInterface
{
    public static function getText(string $filePath, array $options = []): string
    {
        throw new DataReaderException('extraction failed');
    }
}
