<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\TextFileReader;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\Tests\RAG\DataLoader\Stub\FileNameReader;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sort;

class FileDataLoaderTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_file_loader');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_missing_path_is_rejected_on_construction(): void
    {
        $missing = $this->sandbox . '/missing.txt';

        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage('The provided path does not exist: ' . $missing);

        new FileDataLoader($missing);
    }

    public function test_single_file_becomes_a_files_document_named_after_the_given_path(): void
    {
        $path = $this->write('notes.txt', 'Short note');

        $documents = FileDataLoader::for($path)->getDocuments();

        $this->assertCount(1, $documents);
        $this->assertSame('Short note', $documents[0]->getContent());
        $this->assertSame('files', $documents[0]->getSourceType());
        $this->assertSame($path, $documents[0]->getSourceName());
    }

    public function test_text_files_are_read_byte_for_byte(): void
    {
        $content = "Città – naïve 日本語 🚀\r\n\ttabbed\0binary";
        $path = $this->write('unicode.txt', $content);

        $this->assertSame($content, TextFileReader::getText($path));
        $this->assertSame($content, FileDataLoader::for($path)->getDocuments()[0]->getContent());
    }

    public function test_directory_is_loaded_recursively(): void
    {
        $this->write('a.txt', 'Top level');
        $this->write('nested/b.txt', 'First level');
        $this->write('nested/deeper/c.txt', 'Second level');

        $documents = FileDataLoader::for($this->sandbox)->getDocuments();

        $this->assertSame(['First level', 'Second level', 'Top level'], $this->sortedContents($documents));
        foreach ($documents as $document) {
            $this->assertSame('files', $document->getSourceType());
        }
    }

    public function test_empty_directory_has_no_documents(): void
    {
        mkdir($this->sandbox . '/empty/nested', 0o755, true);

        $this->assertSame([], FileDataLoader::for($this->sandbox)->getDocuments());
    }

    public function test_empty_files_produce_no_documents(): void
    {
        $this->write('empty.txt', '');
        $this->write('full.txt', 'Content');

        $this->assertSame(['Content'], $this->sortedContents(FileDataLoader::for($this->sandbox)->getDocuments()));
    }

    public function test_directory_documents_are_split_with_the_configured_splitter(): void
    {
        $this->write('long.txt', 'alpha beta gamma delta');

        $documents = FileDataLoader::for($this->sandbox)
            ->withSplitter(new DelimiterTextSplitter(maxLength: 11))
            ->getDocuments();

        $this->assertSame(['alpha beta', 'gamma delta'], array_map(static fn (Document $document): string => $document->getContent(), $documents));
        foreach ($documents as $document) {
            $this->assertSame('files', $document->getSourceType());
        }
    }

    public function test_files_without_a_registered_reader_are_read_as_plain_text(): void
    {
        $path = $this->write('data.bin', 'raw bytes');

        $documents = FileDataLoader::for($path, ['md' => new FileNameReader()])->getDocuments();

        $this->assertSame('raw bytes', $documents[0]->getContent());
    }

    public function test_constructor_readers_are_used_for_their_extension(): void
    {
        $path = $this->write('guide.md', '# ignored');

        $documents = FileDataLoader::for($path, ['md' => new FileNameReader()])->getDocuments();

        $this->assertSame('read by FileNameReader: guide.md', $documents[0]->getContent());
    }

    public function test_one_reader_can_be_registered_for_several_extensions(): void
    {
        $this->write('page.html', '<p>ignored</p>');
        $this->write('page2.htm', '<p>ignored</p>');
        $this->write('plain.txt', 'plain text');

        $documents = FileDataLoader::for($this->sandbox)
            ->addReader(['html', 'htm'], new FileNameReader())
            ->getDocuments();

        $this->assertSame(
            ['plain text', 'read by FileNameReader: page.html', 'read by FileNameReader: page2.htm'],
            $this->sortedContents($documents)
        );
    }

    public function test_reader_lookup_ignores_the_case_of_the_file_extension(): void
    {
        $path = $this->write('README.MD', '# ignored');

        $documents = FileDataLoader::for($path)->addReader('md', new FileNameReader())->getDocuments();

        $this->assertSame('read by FileNameReader: README.MD', $documents[0]->getContent());
    }

    public function test_added_reader_overrides_the_one_registered_for_the_same_extension(): void
    {
        $path = $this->write('guide.md', 'markdown source');

        $documents = FileDataLoader::for($path, ['md' => new FileNameReader()])
            ->addReader('md', new TextFileReader())
            ->getDocuments();

        $this->assertSame('markdown source', $documents[0]->getContent());
    }

    public function test_set_readers_replaces_previously_registered_readers(): void
    {
        $path = $this->write('guide.md', 'markdown source');

        $documents = FileDataLoader::for($path)
            ->addReader('md', new FileNameReader())
            ->setReaders([])
            ->getDocuments();

        $this->assertSame('markdown source', $documents[0]->getContent());
    }

    protected function write(string $relativePath, string $content): string
    {
        $path = $this->sandbox . '/' . $relativePath;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Directory listing order depends on the filesystem.
     *
     * @param Document[] $documents
     * @return string[]
     */
    protected function sortedContents(array $documents): array
    {
        $contents = array_map(static fn (Document $document): string => $document->getContent(), $documents);
        sort($contents);

        return $contents;
    }
}
