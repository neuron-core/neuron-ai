<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\GraphStore;

use NeuronAI\Tests\RAG\GraphStore\Stub\Neo4jGraphStoreWithClient;
use NeuronAI\Tests\RAG\GraphStore\Stub\RecordingNeo4jClient;
use PHPUnit\Framework\TestCase;

use function preg_replace;

class Neo4jCypherInjectionTest extends TestCase
{
    public function test_a_relation_cannot_change_the_structure_of_the_upsert_statement(): void
    {
        $client = new RecordingNeo4jClient();
        $store = new Neo4jGraphStoreWithClient($client);

        $store->upsert('Alice', 'KNOWS', 'Bob');
        $store->upsert('Alice', "KNOWS`]->(n2)\nWITH\t*\nMATCH\t(X)\nDETACH\tDELETE\tX\n//", 'Bob');

        $this->assertSame(
            $this->structureOf($client->runs[0]['statement']),
            $this->structureOf($client->runs[1]['statement'])
        );
    }

    public function test_a_relation_cannot_change_the_structure_of_the_delete_statement(): void
    {
        $client = new RecordingNeo4jClient();
        $store = new Neo4jGraphStoreWithClient($client);

        $store->delete('Alice', 'KNOWS', 'Bob');
        $store->delete('Alice', "KNOWS`]->()\nWITH\t*\nMATCH\t(X)\nDETACH\tDELETE\tX\n//", 'Bob');

        $this->assertSame(
            $this->structureOf($client->runs[0]['statement']),
            $this->structureOf($client->runs[2]['statement'])
        );
    }

    public function test_a_relation_is_reduced_to_a_plain_identifier(): void
    {
        $client = new RecordingNeo4jClient();
        $store = new Neo4jGraphStoreWithClient($client);

        $store->upsert('Alice', "likes `rock`\tmusic \\U00000060", 'Bob');

        $this->assertStringContainsString('[r:`LIKES_ROCK_MUSIC_U00000060`]', $client->runs[0]['statement']);
    }

    /**
     * Replaces every correctly quoted Cypher identifier (backticks doubled inside) with a placeholder.
     */
    protected function structureOf(string $statement): string
    {
        return (string) preg_replace('/`(?:[^`]|``)*`/', '`?`', $statement);
    }
}
