<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_map;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function posix_setrlimit;
use function rmdir;
use function scandir;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function touch;
use function trim;
use function uniqid;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const FILE_APPEND;
use const NAN;
use const POSIX_RLIMIT_FSIZE;
use const POSIX_RLIMIT_INFINITY;
use const SIG_IGN;
use const SIGXFSZ;

class FileVectorStoreTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/' . uniqid('neuron_file_store_', true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->directory);
    }

    protected function store(int $topK = 4, ?DocumentSchema $schema = null): FileVectorStore
    {
        return new FileVectorStore($this->directory, $topK, schema: $schema);
    }

    /**
     * @param float[] $embedding
     */
    protected function document(string $content, array $embedding, string $sourceType = 'manual'): Document
    {
        return (new Document($content))->setEmbedding($embedding)->setSourceType($sourceType);
    }

    protected function storeFile(): string
    {
        return $this->directory . '/neuron.store';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function storedRows(): array
    {
        $content = trim((string) file_get_contents($this->storeFile()));

        return $content === '' ? [] : array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            explode("\n", $content),
        );
    }

    /**
     * @param iterable<Document> $documents
     * @return string[]
     */
    protected function contents(iterable $documents): array
    {
        $contents = [];
        foreach ($documents as $document) {
            $contents[] = $document->getContent();
        }

        return $contents;
    }

    public function test_creates_the_directory_and_an_empty_store_file(): void
    {
        new FileVectorStore($this->directory . '/nested/deeper');

        $this->assertFileExists($this->directory . '/nested/deeper/neuron.store');
        $this->assertSame('', file_get_contents($this->directory . '/nested/deeper/neuron.store'));
    }

    public function test_custom_name_and_extension_select_the_store_file(): void
    {
        (new FileVectorStore($this->directory, name: 'tenant_a', ext: '.jsonl'))
            ->addDocument($this->document('A', [1, 0]));

        $this->assertFileExists($this->directory . '/tenant_a.jsonl');
        $this->assertFileDoesNotExist($this->storeFile());
    }

    public function test_directory_that_cannot_be_created_is_reported(): void
    {
        mkdir($this->directory);
        touch($this->directory . '/a-file');

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage("Directory '{$this->directory}/a-file/store' does not exist and could not be created.");

        new FileVectorStore($this->directory . '/a-file/store');
    }

    public function test_store_file_that_cannot_be_created_is_reported(): void
    {
        // Longer than any file system allows for a single file name
        $name = str_repeat('a', 300);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage("Store file '{$this->directory}/{$name}.store' does not exist and could not be created.");

        new FileVectorStore($this->directory, name: $name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function namesThatAreNotAFileName(): array
    {
        return [
            'parent directory' => ['../escaped', '.store'],
            'nested folder' => ['tenants/acme', '.store'],
            'windows separator' => ['..\\escaped', '.store'],
            'null byte' => ["neuron\0", '.store'],
            'path in the extension' => ['neuron', '/../escaped.store'],
            'empty' => ['', ''],
            'dot' => ['.', ''],
            'dot dot' => ['..', ''],
        ];
    }

    #[DataProvider('namesThatAreNotAFileName')]
    public function test_a_name_that_is_not_a_file_name_is_refused_before_anything_is_created(string $name, string $ext): void
    {
        mkdir($this->directory);

        try {
            new FileVectorStore($this->directory . '/stores', name: $name, ext: $ext);
            $this->fail('A store name holding a path must be refused.');
        } catch (VectorStoreException $exception) {
            $this->assertSame("Store name '{$name}{$ext}' must be a file name, not a path: put folders in \$directory.", $exception->getMessage());
        }

        $this->assertSame(['.', '..'], scandir($this->directory));
    }

    /**
     * Names are used as given, never encoded: stores created by earlier versions keep their file.
     *
     * @return array<string, array{string, string}>
     */
    public static function namesUsedAsGiven(): array
    {
        return [
            'email' => ['user@example.com', 'user@example.com.store'],
            'space' => ['my docs', 'my docs.store'],
            'dots before the extension' => ['..', '...store'],
        ];
    }

    #[DataProvider('namesUsedAsGiven')]
    public function test_a_name_is_used_as_the_file_name_as_given(string $name, string $fileName): void
    {
        new FileVectorStore($this->directory, name: $name);

        $this->assertSame(['.', '..', $fileName], scandir($this->directory));
    }

    public function test_search_on_fresh_store_returns_no_results(): void
    {
        $this->assertSame([], $this->store()->search(new SearchRequest([1, 2, 3])));
    }

    public function test_persists_one_json_line_per_document_without_runtime_score(): void
    {
        $document = (new Document('Ciao 🌍 "quoted"'))
            ->setId(1)
            ->setEmbedding([1, 2, 3])
            ->setSourceType('string')
            ->setSourceName('test')
            ->setScore(0.9)
            ->setMetadata(['customProperty' => 'customValue', 'nested' => ['a' => [1, 2]]]);

        $this->store()->addDocuments([$document, $this->document('Second', [3, 4, 5])->setId('two')]);

        $rows = $this->storedRows();
        $this->assertCount(2, $rows);
        $this->assertSame([
            'id' => 1,
            'content' => 'Ciao 🌍 "quoted"',
            'embedding' => [1, 2, 3],
            'sourceType' => 'string',
            'sourceName' => 'test',
            'metadata' => ['customProperty' => 'customValue', 'nested' => ['a' => [1, 2]]],
        ], $rows[0]);
        $this->assertSame('two', $rows[1]['id']);
    }

    public function test_search_round_trips_every_stored_attribute(): void
    {
        $document = (new Document('Ciao 🌍'))
            ->setId(7)
            ->setEmbedding([1, 2, 3])
            ->setSourceType('string')
            ->setSourceName('test')
            ->setMetadata(['customProperty' => 'customValue', 'nested' => ['flag' => true]]);

        $this->store()->addDocument($document);
        $result = $this->store()->search(new SearchRequest([1, 2, 3]))[0];

        $this->assertSame(7, $result->getId());
        $this->assertSame('Ciao 🌍', $result->getContent());
        $this->assertSame([1.0, 2.0, 3.0], $result->getEmbedding());
        $this->assertSame('string', $result->getSourceType());
        $this->assertSame('test', $result->getSourceName());
        $this->assertSame(['customProperty' => 'customValue', 'nested' => ['flag' => true]], $result->getMetadata());
        $this->assertEqualsWithDelta(1.0, $result->getScore(), 1e-12);
    }

    public function test_documents_persist_across_store_instances_and_appends(): void
    {
        $this->store()->addDocument($this->document('First', [1, 0]));
        $this->store()->addDocuments([$this->document('Second', [0, 1])]);

        $this->assertSame(['First', 'Second'], $this->contents($this->store()->search(new SearchRequest([1, 0.1]))));
    }

    public function test_search_orders_by_cosine_similarity_with_exact_scores(): void
    {
        $this->store()->addDocuments([
            $this->document('orthogonal', [0, 1]),
            $this->document('opposite', [-1, 0]),
            $this->document('identical', [2, 0]),
            $this->document('diagonal', [1, 1]),
        ]);

        $results = $this->store()->search(new SearchRequest([1, 0]));

        $this->assertSame(['identical', 'diagonal', 'orthogonal', 'opposite'], $this->contents($results));
        $this->assertEqualsWithDelta(
            [1.0, 0.7071067811865476, 0.0, -1.0],
            array_map(static fn (Document $document): ?float => $document->getScore(), $results),
            1e-12,
        );
    }

    public function test_top_k_limits_results_and_request_top_k_overrides_the_default(): void
    {
        $store = $this->store(topK: 2);
        $store->addDocuments([
            $this->document('a', [1, 0]),
            $this->document('b', [1, 0.1]),
            $this->document('c', [1, 0.2]),
            $this->document('d', [0, 1]),
        ]);

        $this->assertSame(['a', 'b'], $this->contents($store->search(new SearchRequest([1, 0]))));
        $this->assertSame(['a', 'b', 'c'], $this->contents($store->search(new SearchRequest([1, 0], topK: 3))));
        $this->assertCount(4, $store->search(new SearchRequest([1, 0], topK: 100)));
    }

    public function test_equally_similar_documents_keep_insertion_order(): void
    {
        $store = $this->store(topK: 2);
        $store->addDocuments([
            $this->document('first', [1, 0]),
            $this->document('second', [2, 0]),
            $this->document('third', [3, 0]),
        ]);

        $this->assertSame(['first', 'second'], $this->contents($store->search(new SearchRequest([1, 0]))));
    }

    public function test_search_rejects_embeddings_of_a_different_dimension(): void
    {
        $store = $this->store();
        $store->addDocument($this->document('A', [1, 0, 0]));

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Vectors must have the same length to apply cosine similarity.');

        $store->search(new SearchRequest([1, 0]));
    }

    public function test_search_applies_filters_before_ranking(): void
    {
        $schema = DocumentSchema::of(DocumentField::string('tenant')->required()->filterable());
        $store = $this->store(topK: 1, schema: $schema);
        $store->addDocuments([
            $this->document('best but other tenant', [1, 0])->addMetadata('tenant', 'globex'),
            $this->document('worse but same tenant', [0.5, 0.5])->addMetadata('tenant', 'acme'),
        ]);

        $results = $store->search(new SearchRequest([1, 0], Filter::eq('tenant', 'acme')));

        $this->assertSame(['worse but same tenant'], $this->contents($results));
    }

    public function test_search_rejects_filters_on_undeclared_fields(): void
    {
        $store = $this->store();

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter field "tenant" is not declared in the vector store document schema.');

        $store->search(new SearchRequest([1, 0], Filter::eq('tenant', 'acme')));
    }

    public function test_delete_removes_only_matching_documents_and_leaves_no_temporary_file(): void
    {
        $store = $this->store();
        $store->addDocuments([
            $this->document('web a', [1, 0], 'web')->setSourceName('page-a'),
            $this->document('file', [0.5, 0.5], 'file')->setSourceName('doc.txt'),
            $this->document('web b', [0, 1], 'web')->setSourceName('page-b'),
            $this->document('api', [0.2, 0.8], 'api')->setSourceName('endpoint'),
        ]);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'web')));

        $this->assertSame(['file', 'api'], array_map(static fn (array $row): string => $row['content'], $this->storedRows()));
        $this->assertStringEndsWith("\n", (string) file_get_contents($this->storeFile()));
        $this->assertSame(['.', '..', 'neuron.store', 'neuron.store.lock'], scandir($this->directory));
    }

    public function test_documents_added_after_a_delete_are_stored_on_their_own_line(): void
    {
        $store = $this->store();
        $store->addDocuments([$this->document('drop', [1, 0], 'web'), $this->document('keep', [0, 1])]);
        $store->delete(Filter::eq('sourceType', 'web'));

        $store->addDocument($this->document('new', [1, 1]));

        $this->assertSame(['keep', 'new'], array_map(static fn (array $row): string => $row['content'], $this->storedRows()));
        $this->assertSame(['new', 'keep'], $this->contents($store->search(new SearchRequest([1, 1]))));
    }

    public function test_delete_by_source_type_and_name_matches_both(): void
    {
        $store = $this->store();
        $store->addDocuments([
            $this->document('keep', [1, 0], 'web')->setSourceName('page-b'),
            $this->document('drop', [0, 1], 'web')->setSourceName('page-a'),
        ]);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'web'), Filter::eq('sourceName', 'page-a')));

        $this->assertSame(['keep'], $this->contents($store->search(new SearchRequest([1, 0]))));
    }

    public function test_deleting_everything_leaves_an_empty_searchable_store(): void
    {
        $store = $this->store();
        $store->addDocuments([$this->document('a', [1, 0]), $this->document('b', [0, 1])]);

        $store->delete(Filter::eq('sourceType', 'manual'));

        $this->assertSame('', file_get_contents($this->storeFile()));
        $this->assertSame([], $store->search(new SearchRequest([1, 0])));
    }

    public function test_delete_rejects_invalid_filters_without_touching_the_file(): void
    {
        $store = $this->store();
        $store->addDocument($this->document('a', [1, 0]));
        $before = file_get_contents($this->storeFile());

        try {
            $store->delete(Filter::eq('tenant', 'acme'));
            $this->fail('An undeclared filter field must be rejected.');
        } catch (DocumentSchemaException $exception) {
            $this->assertSame('Filter field "tenant" is not declared in the vector store document schema.', $exception->getMessage());
        }

        $this->assertSame($before, file_get_contents($this->storeFile()));
    }

    public function test_invalid_batch_is_rejected_without_writing_any_document(): void
    {
        $store = $this->store();

        try {
            $store->addDocuments([$this->document('valid', [1, 0]), (new Document('no vector'))->setId('missing')]);
            $this->fail('A document without embedding must be rejected.');
        } catch (VectorStoreException $exception) {
            $this->assertSame('Document missing must have an embedding before it can be stored.', $exception->getMessage());
        }

        $this->assertSame('', file_get_contents($this->storeFile()));
    }

    public function test_an_empty_batch_writes_nothing(): void
    {
        $this->store()->addDocuments([]);

        $this->assertSame('', file_get_contents($this->storeFile()));
    }

    /**
     * @return array<string, array{string, string, float[], string}>
     */
    public static function documentsTheFileCannotHold(): array
    {
        return [
            'content with invalid UTF-8' => ["broken \xB1 utf-8", 'manual', [1, 0], 'Malformed UTF-8 characters, possibly incorrectly encoded'],
            'source name with invalid UTF-8' => ['content', "file-\xC3\x28.txt", [1, 0], 'Malformed UTF-8 characters, possibly incorrectly encoded'],
            'embedding with NaN' => ['content', 'manual', [NAN, 0], 'Inf and NaN cannot be JSON encoded'],
        ];
    }

    /**
     * @param float[] $embedding
     */
    #[DataProvider('documentsTheFileCannotHold')]
    public function test_a_document_the_file_cannot_hold_refuses_the_whole_batch(string $content, string $sourceName, array $embedding, string $reason): void
    {
        $store = $this->store();
        $document = (new Document($content))->setEmbedding($embedding)->setSourceName($sourceName)->setId('broken');

        try {
            $store->addDocuments([$this->document('valid', [1, 0]), $document]);
            $this->fail('A document that cannot be encoded must be refused.');
        } catch (VectorStoreException $exception) {
            $this->assertSame("Document broken is not JSON serializable: {$reason}", $exception->getMessage());
        }

        $this->assertSame('', file_get_contents($this->storeFile()));
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function test_a_batch_the_disk_cannot_take_leaves_the_store_as_it_was(): void
    {
        $store = $this->store();
        $store->addDocument($this->document('kept', [1, 0]));
        $before = (string) file_get_contents($this->storeFile());

        $child = pcntl_fork();
        if ($child === 0) {
            // Room for ten more bytes, like a disk about to fill up: the next row is cut short
            pcntl_signal(SIGXFSZ, SIG_IGN);
            posix_setrlimit(POSIX_RLIMIT_FSIZE, strlen($before) + 10, POSIX_RLIMIT_INFINITY);

            try {
                $store->addDocument($this->document('cut short', [0, 1]));
                exit(1);
            } catch (VectorStoreException) {
                exit(0);
            }
        }
        pcntl_waitpid($child, $status);

        $this->assertSame(0, pcntl_wexitstatus($status), 'A short write must be reported.');
        $this->assertSame($before, file_get_contents($this->storeFile()));
    }

    public function test_blank_lines_written_by_older_versions_are_skipped(): void
    {
        $store = $this->store();
        $store->addDocument($this->document('web', [1, 0], 'web'));
        // What the old write path left for an empty batch or a document it could not encode
        file_put_contents($this->storeFile(), "\n", FILE_APPEND);
        $store->addDocument($this->document('kept', [0, 1]));

        $this->assertSame(['web', 'kept'], $this->contents($store->search(new SearchRequest([1, 0]))));
        $this->assertSame(['kept'], $this->contents($store->search(new SearchRequest([1, 0], filters: Filter::eq('sourceType', 'manual')))));

        $store->delete(Filter::eq('sourceType', 'web'));

        $this->assertSame(['kept'], array_map(static fn (array $row): string => $row['content'], $this->storedRows()));
        $this->assertStringNotContainsString("\n\n", (string) file_get_contents($this->storeFile()));
    }

    public function test_search_skips_a_row_another_process_is_still_appending(): void
    {
        $store = $this->store();
        $store->addDocument($this->document('complete', [1, 0]));
        file_put_contents($this->storeFile(), '{"id":"x","content":"half written","embed', FILE_APPEND);

        $this->assertSame(['complete'], $this->contents($store->search(new SearchRequest([1, 0]))));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rowsWithoutEmbedding(): array
    {
        return [
            'null embedding' => ['{"id":"x","content":"orphan","embedding":null,"sourceType":"manual","sourceName":"manual","metadata":{}}'],
            'empty embedding' => ['{"id":"x","content":"orphan","embedding":[],"sourceType":"manual","sourceName":"manual","metadata":{}}'],
            'missing embedding' => ['{"id":"x","content":"orphan","sourceType":"manual","sourceName":"manual","metadata":{}}'],
        ];
    }

    #[DataProvider('rowsWithoutEmbedding')]
    public function test_stored_rows_without_embedding_are_reported_on_search(string $row): void
    {
        $store = $this->store();
        file_put_contents($this->storeFile(), $row . "\n");

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Document with the following content has no embedding: orphan');

        $store->search(new SearchRequest([1, 0]));
    }
}
