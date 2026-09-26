<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

use function array_shift;
use function count;
use function preg_replace;
use function trim;

/**
 * Offline contract of the SQL sent by the MariaDB store: statements are
 * prepared once and every filter value travels as a bound parameter.
 * MariaDBTest covers a live server.
 */
class MariaDBVectorStoreQueryTest extends TestCase
{
    use RejectsInvalidInputBeforeRemoteCalls;

    /**
     * @var string[]
     */
    protected array $sentSql = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $executedBindings = [];

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    protected function store(array $rows = [], ?DocumentSchema $schema = null, int $topK = 4): MariaDBVectorStore
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(function (?array $bindings = null): bool {
            $this->executedBindings[] = $bindings ?? [];
            return true;
        });
        $statement->method('fetch')->willReturnCallback(static function () use (&$rows): array|false {
            return array_shift($rows) ?? false;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql): int {
            $this->sentSql[] = trim((string) preg_replace('/\s+/', ' ', $sql));
            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($statement): PDOStatement {
            $this->sentSql[] = trim((string) preg_replace('/\s+/', ' ', $sql));
            return $statement;
        });

        return new MariaDBVectorStore($pdo, 'rag_documents', $topK, $schema);
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
        );
    }

    public function test_setup_creates_a_vector_table_sized_to_the_dimensions(): void
    {
        $this->store()->setupTable(768);

        $this->assertSame([
            'CREATE TABLE IF NOT EXISTS rag_documents ( id UUID NOT NULL PRIMARY KEY, content TEXT, ' .
            'sourceType VARCHAR(255), sourceName VARCHAR(255), metadata JSON, ' .
            'embedding VECTOR(768) NOT NULL, VECTOR INDEX (embedding) )',
        ], $this->sentSql);
    }

    public function test_drop_table_removes_the_configured_table(): void
    {
        $this->store()->dropTable();

        $this->assertSame(['DROP TABLE IF EXISTS rag_documents'], $this->sentSql);
    }

    public function test_search_without_filters_orders_by_distance_and_limits_to_top_k(): void
    {
        $this->store(topK: 2)->search(new SearchRequest([0.5, 1]));

        $this->assertSame([
            'SELECT id, content, sourceType, sourceName, metadata, ' .
            'VEC_DISTANCE_EUCLIDEAN(embedding, VEC_FromText(:embedding)) AS distance ' .
            'FROM rag_documents ORDER BY distance ASC LIMIT 2',
        ], $this->sentSql);
        $this->assertSame([[':embedding' => '[0.5,1]']], $this->executedBindings);
    }

    public function test_search_binds_filter_values_instead_of_interpolating_them(): void
    {
        $hostile = "acme' OR '1'='1";

        $this->store([], $this->schema())->search(new SearchRequest(
            [1.0],
            FilterGroup::allOf(Filter::eq('tenant', $hostile), Filter::gte('year', 2020)),
            topK: 9,
        ));

        $this->assertSame([
            'SELECT id, content, sourceType, sourceName, metadata, ' .
            'VEC_DISTANCE_EUCLIDEAN(embedding, VEC_FromText(:embedding)) AS distance ' .
            "FROM rag_documents WHERE JSON_VALUE(metadata, '$.tenant') = :f0 " .
            "AND CAST(JSON_VALUE(metadata, '$.year') AS SIGNED) >= :f1 ORDER BY distance ASC LIMIT 9",
        ], $this->sentSql);
        $this->assertStringNotContainsString($hostile, $this->sentSql[0]);
        $this->assertSame([[':embedding' => '[1]', ':f0' => $hostile, ':f1' => 2020]], $this->executedBindings);
    }

    public function test_search_maps_rows_to_documents_with_scores_and_metadata(): void
    {
        $results = $this->store([
            [
                'id' => 'a1',
                'content' => 'First',
                'sourceType' => 'file',
                'sourceName' => 'a.txt',
                'metadata' => '{"tenant":"acme","year":2026}',
                'distance' => '0.25',
            ],
            [
                'id' => 'b2',
                'content' => 'Second',
                'sourceType' => 'url',
                'sourceName' => 'b',
                'metadata' => null,
                'distance' => '0',
            ],
        ])->search(new SearchRequest([1.0]));

        $this->assertCount(2, $results);
        $this->assertSame('a1', $results[0]->getId());
        $this->assertSame('First', $results[0]->getContent());
        $this->assertSame('file', $results[0]->getSourceType());
        $this->assertSame('a.txt', $results[0]->getSourceName());
        $this->assertSame(0.75, $results[0]->getScore());
        $this->assertSame(['tenant' => 'acme', 'year' => 2026], $results[0]->getMetadata());
        $this->assertSame(1.0, $results[1]->getScore());
        $this->assertSame([], $results[1]->getMetadata());
    }

    public function test_stored_metadata_cannot_override_framework_fields(): void
    {
        $results = $this->store([[
            'id' => 'a1',
            'content' => 'Real',
            'sourceType' => 'file',
            'sourceName' => 'a.txt',
            'metadata' => '{"id":"forged","content":"forged","sourceType":"forged","sourceName":"forged","score":9,"embedding":[1],"tenant":"acme"}',
            'distance' => '0.5',
        ]])->search(new SearchRequest([1.0]));

        $this->assertSame('a1', $results[0]->getId());
        $this->assertSame('Real', $results[0]->getContent());
        $this->assertSame('file', $results[0]->getSourceType());
        $this->assertSame('a.txt', $results[0]->getSourceName());
        $this->assertSame(0.5, $results[0]->getScore());
        $this->assertSame(['tenant' => 'acme'], $results[0]->getMetadata());
    }

    public function test_delete_uses_the_compiled_where_clause_with_bindings(): void
    {
        $this->store()->delete(FilterGroup::anyOf(
            Filter::eq('sourceType', 'web'),
            Filter::in('sourceName', ['a', 'b']),
        ));

        $this->assertSame(['DELETE FROM rag_documents WHERE sourceType = :f0 OR sourceName IN (:f1, :f2)'], $this->sentSql);
        $this->assertSame([[':f0' => 'web', ':f1' => 'a', ':f2' => 'b']], $this->executedBindings);
    }

    public function test_adds_documents_through_one_prepared_upsert(): void
    {
        $first = (new Document('One'))->setId('id-1')->setEmbedding([1, 0])->setMetadata(['tenant' => 'acme']);
        $second = (new Document('Two'))->setId('id-2')->setEmbedding([0, 1])->setSourceType('file')->setSourceName('b.txt')
            ->setMetadata(['tenant' => 'acme', 'year' => 2026]);

        $this->store([], $this->schema())->addDocuments([$first, $second]);

        $this->assertCount(1, $this->sentSql);
        $this->assertStringStartsWith(
            'INSERT INTO rag_documents (id, content, sourceType, sourceName, metadata, embedding) ' .
            'VALUES (:id, :content, :sourceType, :sourceName, :metadata, VEC_FromText(:embedding)) ON DUPLICATE KEY UPDATE',
            $this->sentSql[0],
        );
        $this->assertSame([
            [
                ':id' => 'id-1',
                ':content' => 'One',
                ':sourceType' => 'manual',
                ':sourceName' => 'manual',
                ':metadata' => '{"tenant":"acme"}',
                ':embedding' => '[1,0]',
            ],
            [
                ':id' => 'id-2',
                ':content' => 'Two',
                ':sourceType' => 'file',
                ':sourceName' => 'b.txt',
                ':metadata' => '{"tenant":"acme","year":2026}',
                ':embedding' => '[0,1]',
            ],
        ], $this->executedBindings);
    }

    public function test_list_and_nested_metadata_are_stored_as_json_arrays_and_objects(): void
    {
        $document = (new Document('Tagged'))
            ->setId('id-3')
            ->setEmbedding([1, 0])
            ->setMetadata(['tags' => ['php', 'rag'], 'author' => ['name' => 'Zoë'], 'empty' => []]);

        $this->store()->addDocument($document);

        $this->assertSame(
            '{"tags":["php","rag"],"author":{"name":"Zo\\u00eb"},"empty":[]}',
            $this->executedBindings[0][':metadata'],
        );
    }

    public function test_adding_no_documents_touches_nothing(): void
    {
        $this->store()->addDocuments([]);

        $this->assertSame([], $this->sentSql);
    }

    protected function storeRejectingInvalidInput(): VectorStoreInterface
    {
        return $this->store();
    }

    protected function remoteCallCount(): int
    {
        return count($this->sentSql) + count($this->executedBindings);
    }
}
