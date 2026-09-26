<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

use function array_shift;

class MariaDBReservedMetadataReproTest extends TestCase
{
    public function test_stored_metadata_with_any_reserved_key_does_not_break_search(): void
    {
        $rows = [[
            'id' => 'a1',
            'content' => 'Real',
            'sourceType' => 'file',
            'sourceName' => 'a.txt',
            'metadata' => '{"_vectors":[1],"metadata":"x","vector_distance":0.1,"tenant":"acme"}',
            'distance' => '0.5',
        ]];
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturnCallback(static function () use (&$rows): array|false {
            return array_shift($rows) ?? false;
        });
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $results = (new MariaDBVectorStore($pdo))->search(new SearchRequest([1.0]));

        $this->assertSame(['tenant' => 'acme'], $results[0]->getMetadata());
    }
}
