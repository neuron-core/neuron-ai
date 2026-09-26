<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_filter;
use function file;
use function glob;
use function is_dir;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function usleep;
use function unlink;
use function json_decode;

use const FILE_IGNORE_NEW_LINES;

#[RequiresPhpExtension('pcntl')]
class FileVectorStoreConcurrencyTest extends TestCase
{
    protected const APPENDS_PER_WRITER = 200;

    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/' . uniqid('neuron_file_store_race_', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    protected function store(): FileVectorStore
    {
        return new FileVectorStore($this->directory);
    }

    protected function document(string $content): Document
    {
        return (new Document($content))->setEmbedding([1.0, 0.0])->setSourceType('manual');
    }

    /**
     * @param callable(): void $work
     */
    protected function spawn(callable $work): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $work();
                exit(0);
            } catch (Throwable) {
                exit(1);
            }
        }

        return $pid;
    }

    protected function wait(int $pid): int
    {
        pcntl_waitpid($pid, $status);

        return pcntl_wexitstatus($status);
    }

    public function test_a_delete_matching_nothing_does_not_lose_documents_appended_concurrently(): void
    {
        $seed = [];
        for ($i = 0; $i < 200; $i++) {
            $seed[] = $this->document("seed {$i}");
        }
        $this->store()->addDocuments($seed);

        $writer = $this->spawn(function (): void {
            $store = $this->store();
            for ($i = 0; $i < self::APPENDS_PER_WRITER; $i++) {
                $store->addDocument($this->document("appended {$i}"));
                usleep(100);
            }
        });
        $deleter = $this->spawn(function (): void {
            $store = $this->store();
            for ($i = 0; $i < self::APPENDS_PER_WRITER; $i++) {
                $store->delete(Filter::eq('sourceType', 'nothing-matches'));
            }
        });

        $this->assertSame(0, $this->wait($writer), 'writer process failed');
        $this->assertSame(0, $this->wait($deleter), 'deleter process failed');

        $lines = file($this->directory . '/neuron.store', FILE_IGNORE_NEW_LINES);
        $this->assertCount(200 + self::APPENDS_PER_WRITER, $lines);
    }

    public function test_concurrent_large_appends_never_produce_corrupted_lines(): void
    {
        $this->store();
        $payload = str_repeat('x', 256 * 1024);

        $writers = [];
        for ($w = 0; $w < 4; $w++) {
            $writers[] = $this->spawn(function () use ($payload): void {
                $store = $this->store();
                for ($i = 0; $i < 20; $i++) {
                    $store->addDocument($this->document($payload));
                }
            });
        }
        foreach ($writers as $pid) {
            $this->assertSame(0, $this->wait($pid));
        }

        $lines = file($this->directory . '/neuron.store', FILE_IGNORE_NEW_LINES);
        $this->assertCount(80, $lines);
        $this->assertCount(80, array_filter($lines, fn (string $line): bool => json_decode($line, true) !== null));
        $this->assertCount(4, $this->store()->search(new SearchRequest([1.0, 0.0])));
    }

    public function test_a_search_running_while_another_process_deletes_never_fails(): void
    {
        $this->store()->addDocuments([$this->document('a'), $this->document('b')]);

        $deleter = $this->spawn(function (): void {
            $store = $this->store();
            for ($i = 0; $i < 300; $i++) {
                $store->delete(Filter::eq('sourceType', 'nothing-matches'));
            }
        });
        $reader = $this->spawn(function (): void {
            $store = $this->store();
            for ($i = 0; $i < 300; $i++) {
                $store->search(new SearchRequest([1.0, 0.0]));
            }
        });

        $this->assertSame(0, $this->wait($deleter), 'deleter process failed');
        $this->assertSame(0, $this->wait($reader), 'reader process failed');
    }
}
