<?php

declare(strict_types=1);

namespace NeuronAI\Tests\GraphStore;

use NeuronAI\RAG\GraphStore\GraphStoreInterface;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use NeuronAI\RAG\GraphStore\Triplet;
use NeuronAI\Tests\Traits\CheckOpenPort;
use PHPUnit\Framework\TestCase;
use Exception;

use function count;

class Neo4jGraphStoreTest extends TestCase
{
    use CheckOpenPort;

    protected Neo4jGraphStore $store;

    protected function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 7687)) {
            $this->markTestSkipped('Port 7687 is not open. Skipping test.');
        }

        $this->store = new Neo4jGraphStore(
            uri: 'bolt://localhost:7687',
            username: 'neo4j',
            password: 'test_password',
            database: 'neo4j',
            nodeLabel: 'TestEntity'
        );

        // Clean up any existing test data
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    protected function cleanupTestData(): void
    {
        try {
            $this->store->query('MATCH (n:TestEntity) DETACH DELETE n');
        } catch (Exception) {
            // Ignore cleanup errors
        }
    }

    public function test_instance(): void
    {
        $this->assertInstanceOf(GraphStoreInterface::class, $this->store);
    }

    public function test_upsert_and_get(): void
    {
        // Upsert a triplet
        $this->store->upsert('Alice', 'KNOWS', 'Bob');

        // Retrieve triplets for Alice
        $triplets = $this->store->get('Alice');

        $this->assertCount(1, $triplets);
        $this->assertInstanceOf(Triplet::class, $triplets[0]);
        $this->assertEquals('Alice', $triplets[0]->subject);
        $this->assertEquals('KNOWS', $triplets[0]->relation);
        $this->assertEquals('Bob', $triplets[0]->object);
    }

    public function test_delete(): void
    {
        // Setup: create triplets
        $this->store->upsert('Alice', 'KNOWS', 'Bob');
        $this->store->upsert('Alice', 'WORKS_WITH', 'Charlie');

        // Delete one relationship
        $this->store->delete('Alice', 'KNOWS', 'Bob');

        // Verify only one relationship remains
        $triplets = $this->store->get('Alice');
        $this->assertCount(1, $triplets);
        $this->assertInstanceOf(Triplet::class, $triplets[0]);
        $this->assertEquals('Alice', $triplets[0]->subject);
        $this->assertEquals('WORKS_WITH', $triplets[0]->relation);
        $this->assertEquals('Charlie', $triplets[0]->object);
    }

    public function test_get_relationship_map(): void
    {
        // Setup: create a chain of relationships
        $this->store->upsert('Alice', 'KNOWS', 'Bob');
        $this->store->upsert('Bob', 'KNOWS', 'Charlie');
        $this->store->upsert('Charlie', 'KNOWS', 'Dave');

        // Get relationship map with depth 2
        $relationshipMap = $this->store->getRelationshipMap(['Alice'], depth: 2);

        $this->assertArrayHasKey('Alice', $relationshipMap);
        $this->assertNotEmpty($relationshipMap['Alice']);

        // Should include Alice->Bob and Bob->Charlie (depth 2)
        $this->assertGreaterThanOrEqual(2, count($relationshipMap['Alice']));
    }

    public function test_get_schema(): void
    {
        // Setup: create some data to populate schema
        $this->store->upsert('Alice', 'KNOWS', 'Bob');
        $this->store->upsert('Bob', 'WORKS_WITH', 'Charlie');

        $schema = $this->store->getSchema(refresh: true);

        $this->assertNotEmpty($schema);
        $this->assertStringContainsString('TestEntity', $schema);
        $this->assertStringContainsString('KNOWS', $schema);
    }

    public function test_query(): void
    {
        // Setup: create test data
        $this->store->upsert('Alice', 'KNOWS', 'Bob');

        // Execute custom Cypher query
        $result = $this->store->query(
            'MATCH (n:TestEntity {id: $name}) RETURN n.id AS name',
            ['name' => 'Alice']
        );

        $this->assertNotEmpty($result);
        $this->assertEquals('Alice', $result[0]['name']);
    }

    public function test_multiple_upserts_are_idempotent(): void
    {
        // Upsert the same triplet multiple times
        $this->store->upsert('Alice', 'KNOWS', 'Bob');
        $this->store->upsert('Alice', 'KNOWS', 'Bob');
        $this->store->upsert('Alice', 'KNOWS', 'Bob');

        // Should only have one relationship
        $triplets = $this->store->get('Alice');
        $this->assertCount(1, $triplets);
    }

    public function test_relationship_type_normalization(): void
    {
        // Test that relation names with spaces are normalized
        $this->store->upsert('Alice', 'works with', 'Bob');

        $triplets = $this->store->get('Alice');

        $this->assertCount(1, $triplets);
        $this->assertInstanceOf(Triplet::class, $triplets[0]);
        // Relationship type should be normalized to uppercase with underscores
        $this->assertEquals('WORKS_WITH', $triplets[0]->relation);
    }
}
