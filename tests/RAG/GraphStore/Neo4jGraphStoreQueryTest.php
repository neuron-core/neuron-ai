<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\GraphStore;

use InvalidArgumentException;
use Laudis\Neo4j\Client;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use Laudis\Neo4j\Types\Node;
use Laudis\Neo4j\Types\Relationship;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use NeuronAI\RAG\GraphStore\Triplet;
use NeuronAI\Tests\RAG\GraphStore\Stub\Neo4jGraphStoreWithClient;
use NeuronAI\Tests\RAG\GraphStore\Stub\RecordingNeo4jClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_keys;
use function array_map;
use function array_slice;
use function chr;
use function preg_replace;
use function strtoupper;
use function trim;

class Neo4jGraphStoreQueryTest extends TestCase
{
    protected const HOSTILE_ENTITY = "Alice'}) MATCH (x) DETACH DELETE x //";

    protected RecordingNeo4jClient $client;

    protected Neo4jGraphStore $store;

    protected function setUp(): void
    {
        $this->client = new RecordingNeo4jClient();
        $this->store = new Neo4jGraphStoreWithClient($this->client, nodeLabel: 'Person');
    }

    public function test_upsert_merges_both_entities_and_the_relationship_with_bound_values(): void
    {
        $this->store->upsert(self::HOSTILE_ENTITY, 'KNOWS', 'Bob $object');

        $this->assertCount(1, $this->client->runs);
        $run = $this->client->runs[0];
        $this->assertSame(['subject' => self::HOSTILE_ENTITY, 'object' => 'Bob $object', 'relationshipType' => 'KNOWS'], $run['parameters']);
        $this->assertSame(
            'MERGE (n1:`Person` {id: $subject}) MERGE (n2:`Person` {id: $object}) MERGE (n1)-[r:$($relationshipType)]->(n2)',
            $this->statement(0)
        );
    }

    public function test_upsert_normalizes_the_relation_into_an_uppercase_relationship_type(): void
    {
        $this->store->upsert('Alice', 'works with', 'Bob');

        $this->assertSame('WORKS_WITH', $this->client->runs[0]['parameters']['relationshipType']);
    }

    public function test_delete_removes_the_relationship_then_the_orphaned_entities_with_bound_values(): void
    {
        $this->store->delete(self::HOSTILE_ENTITY, 'works with', 'Bob');

        $this->assertCount(2, $this->client->runs);
        [$relationshipDeletion, $orphanCleanup] = $this->client->runs;

        $this->assertSame(
            'MATCH (n1:`Person`)-[r:$($relationshipType)]->(n2:`Person`) WHERE n1.id = $subject AND n2.id = $object DELETE r',
            $this->statement(0)
        );
        $this->assertSame(
            'MATCH (n:`Person`) WHERE n.id IN [$subject, $object] AND NOT (n)-[]-() DELETE n',
            $this->statement(1)
        );
        $this->assertSame(['subject' => self::HOSTILE_ENTITY, 'object' => 'Bob', 'relationshipType' => 'WORKS_WITH'], $relationshipDeletion['parameters']);
        $this->assertSame(['subject' => self::HOSTILE_ENTITY, 'object' => 'Bob'], $orphanCleanup['parameters']);
    }

    public function test_a_relation_never_changes_the_upsert_or_delete_statement(): void
    {
        $hostile = "KNOWS`]->(n2)\nWITH\t*\nMATCH\t(X)\nDETACH\tDELETE\tX\n//";

        $this->store->upsert('Alice', 'KNOWS', 'Bob');
        $this->store->upsert('Alice', $hostile, 'Bob');
        $this->store->delete('Alice', 'KNOWS', 'Bob');
        $this->store->delete('Alice', $hostile, 'Bob');

        $this->assertSame($this->client->runs[0]['statement'], $this->client->runs[1]['statement']);
        $this->assertSame($this->client->runs[2]['statement'], $this->client->runs[4]['statement']);
        $this->assertSame(strtoupper($hostile), $this->client->runs[1]['parameters']['relationshipType']);
        $this->assertSame(strtoupper($hostile), $this->client->runs[4]['parameters']['relationshipType']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeNodeLabelProvider(): iterable
    {
        yield 'backtick' => ['Person`) MATCH (x) DETACH DELETE x //'];
        yield 'unicode escape of a backtick' => ['Person' . chr(92) . 'u0060) MATCH (x) DETACH DELETE x //'];
        yield 'backslash of a class name' => ['App' . chr(92) . 'Models' . chr(92) . 'Entity'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafeNodeLabelProvider')]
    public function test_a_node_label_that_could_change_the_statement_is_refused(string $nodeLabel): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Neo4jGraphStore(nodeLabel: $nodeLabel);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeNodeLabelProvider(): iterable
    {
        yield 'default' => ['Entity'];
        yield 'digits and underscores' => ['Tenant_42_Entity'];
        yield 'accented letters' => ['Persönlichkeit'];
        yield 'non-latin letters' => ['実体'];
        yield 'dash' => ['Knowledge-Entity'];
        yield 'space' => ['Knowledge Entity'];
        yield 'colon' => ['Person:Admin'];
    }

    #[DataProvider('safeNodeLabelProvider')]
    public function test_a_node_label_without_a_backtick_or_a_backslash_is_accepted(string $nodeLabel): void
    {
        $store = new Neo4jGraphStoreWithClient($this->client, nodeLabel: $nodeLabel);

        $store->upsert('Alice', 'KNOWS', 'Bob');

        $this->assertStringContainsString("(n1:`{$nodeLabel}` {id: \$subject})", $this->client->runs[0]['statement']);
    }

    public function test_get_maps_outgoing_relationships_to_triplets_of_the_requested_subject(): void
    {
        $this->client->willReturn([
            ['relation' => 'KNOWS', 'object' => 'Bob'],
            ['relation' => 'WORKS_AT', 'object' => 'Acme'],
        ]);

        $triplets = $this->store->get('Alice');

        $this->assertSame(['subject' => 'Alice'], $this->client->runs[0]['parameters']);
        $this->assertSame(
            'MATCH (n1:`Person`)-[r]->(n2:`Person`) WHERE n1.id = $subject RETURN type(r) AS relation, n2.id AS object',
            $this->statement(0)
        );
        $this->assertSame(
            [['Alice', 'KNOWS', 'Bob'], ['Alice', 'WORKS_AT', 'Acme']],
            array_map(static fn (Triplet $triplet): array => $triplet->toArray(), $triplets)
        );
    }

    public function test_get_returns_no_triplets_for_an_unknown_subject(): void
    {
        $this->assertSame([], $this->store->get('Nobody'));
    }

    public function test_client_sessions_use_the_configured_database(): void
    {
        $client = (new Neo4jGraphStore(database: 'knowledge'))->client();

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('knowledge', $client->getDefaultSessionConfiguration()->getDatabase());
    }

    public function test_client_sessions_use_the_server_home_database_by_default(): void
    {
        $client = (new Neo4jGraphStore())->client();

        $this->assertInstanceOf(Client::class, $client);
        $this->assertNull($client->getDefaultSessionConfiguration()->getDatabase());
    }

    public function test_relationship_map_of_no_subjects_does_not_query_the_graph(): void
    {
        $this->assertSame([], $this->store->getRelationshipMap([]));
        $this->assertSame([], $this->client->runs);
    }

    public function test_relationship_map_binds_subjects_and_applies_depth_and_limit(): void
    {
        $this->store->getRelationshipMap(['Alice', self::HOSTILE_ENTITY], depth: 3, limit: 7);

        $run = $this->client->runs[0];
        $this->assertSame(['subjects' => ['Alice', self::HOSTILE_ENTITY]], $run['parameters']);
        $this->assertStringNotContainsString(self::HOSTILE_ENTITY, $run['statement']);
        $this->assertStringContainsString('(n1:`Person`)-[*1..3]->(n2:`Person`)', $run['statement']);
        $this->assertStringContainsString('WHERE n1.id IN $subjects', $run['statement']);
        $this->assertStringContainsString('LIMIT 7', $run['statement']);
    }

    public function test_relationship_map_explores_two_hops_and_thirty_relationships_by_default(): void
    {
        $this->store->getRelationshipMap(['Alice']);

        $this->assertStringContainsString('(n1:`Person`)-[*1..2]->(n2:`Person`)', $this->statement(0));
        $this->assertStringContainsString('LIMIT 30 RETURN', $this->statement(0));
    }

    public function test_relationship_map_limits_distinct_relationships_keeping_their_own_start_node(): void
    {
        $this->store->getRelationshipMap(['Alice'], depth: 3, limit: 7);

        $this->assertSame(
            'MATCH path = (n1:`Person`)-[*1..3]->(n2:`Person`) WHERE n1.id IN $subjects UNWIND relationships(path) AS rel '
            . 'WITH DISTINCT n1.id AS subject, startNode(rel).id AS source, type(rel) AS relation, endNode(rel).id AS target '
            . 'LIMIT 7 RETURN subject, collect([source, relation, target]) AS rels',
            $this->statement(0)
        );
    }

    public function test_relationship_map_groups_triplets_by_subject(): void
    {
        $this->client->willReturn([
            ['subject' => 'Alice', 'rels' => new CypherList([new CypherList(['Alice', 'KNOWS', 'Bob']), new CypherList(['Bob', 'LIKES', 'Tea'])])],
            ['subject' => 'Bob', 'rels' => new CypherList([new CypherList(['Bob', 'WORKS_AT', 'Acme'])])],
        ]);

        $map = $this->store->getRelationshipMap(['Alice', 'Bob']);

        $this->assertSame(['Alice', 'Bob'], array_keys($map));
        $this->assertSame(
            [['Alice', 'KNOWS', 'Bob'], ['Bob', 'LIKES', 'Tea']],
            array_map(static fn (Triplet $triplet): array => $triplet->toArray(), $map['Alice'])
        );
        $this->assertSame(
            [['Bob', 'WORKS_AT', 'Acme']],
            array_map(static fn (Triplet $triplet): array => $triplet->toArray(), $map['Bob'])
        );
    }

    public function test_schema_lists_node_labels_and_relationship_types(): void
    {
        $this->client->willReturn([$this->schemaVisualization(['Person', 'Company'], ['WORKS_AT', 'KNOWS'])]);

        $this->assertSame(
            "Node Labels:\n  - Person\n  - Company\n\nRelationship Types:\n  - WORKS_AT\n  - KNOWS\n",
            $this->store->getSchema()
        );
        $this->assertStringContainsString('db.schema.visualization()', $this->client->runs[0]['statement']);
    }

    public function test_schema_is_cached_until_a_refresh_is_requested(): void
    {
        $this->client
            ->willReturn([$this->schemaVisualization(['Person'], ['KNOWS'])])
            ->willReturn([$this->schemaVisualization(['Person', 'Company'], ['KNOWS'])]);

        $first = $this->store->getSchema();
        $cached = $this->store->getSchema();
        $refreshed = $this->store->getSchema(refresh: true);

        $this->assertSame($first, $cached);
        $this->assertCount(2, $this->client->runs);
        $this->assertStringContainsString('  - Company', $refreshed);
    }

    public function test_upsert_invalidates_the_cached_schema(): void
    {
        $this->client
            ->willReturn([$this->schemaVisualization(['Person'], [])])
            ->willReturn([])
            ->willReturn([$this->schemaVisualization(['Person'], ['KNOWS'])]);

        $this->store->getSchema();
        $this->store->upsert('Alice', 'knows', 'Bob');

        $this->assertStringContainsString('  - KNOWS', $this->store->getSchema());
    }

    public function test_delete_invalidates_the_cached_schema(): void
    {
        $this->client
            ->willReturn([$this->schemaVisualization(['Person'], ['KNOWS'])])
            ->willReturn([])
            ->willReturn([])
            ->willReturn([$this->schemaVisualization(['Person'], [])]);

        $this->store->getSchema();
        $this->store->delete('Alice', 'knows', 'Bob');

        $this->assertStringNotContainsString('KNOWS', $this->store->getSchema());
    }

    public function test_schema_falls_back_to_label_and_relationship_type_procedures_and_caches_the_result(): void
    {
        $this->client
            ->willThrow(new RuntimeException('Unknown procedure db.schema.visualization'))
            ->willReturn([['label' => 'Person'], ['label' => 'Company']])
            ->willReturn([['relationshipType' => 'WORKS_AT']]);

        $schema = $this->store->getSchema();

        $this->assertSame("Node Labels:\n  - Person\n  - Company\n\nRelationship Types:\n  - WORKS_AT\n", $schema);
        $this->assertSame(
            ['CALL db.labels()', 'CALL db.relationshipTypes()'],
            array_column(array_slice($this->client->runs, 1), 'statement')
        );
        $this->assertSame($schema, $this->store->getSchema());
        $this->assertCount(3, $this->client->runs);
    }

    public function test_query_forwards_statement_and_parameters_and_returns_rows_as_arrays(): void
    {
        $this->client->willReturn([['name' => 'Alice', 'age' => 30], ['name' => 'Bob', 'age' => 25]]);

        $rows = $this->store->query('MATCH (n:Person {id: $id}) RETURN n.id AS name', ['id' => 'Alice']);

        $this->assertSame(
            [['statement' => 'MATCH (n:Person {id: $id}) RETURN n.id AS name', 'parameters' => ['id' => 'Alice']]],
            $this->client->runs
        );
        $this->assertSame([['name' => 'Alice', 'age' => 30], ['name' => 'Bob', 'age' => 25]], $rows);
    }

    public function test_client_is_built_lazily_once_without_connecting(): void
    {
        $store = new Neo4jGraphStore(uri: 'bolt://127.0.0.1:1');

        $this->assertSame($store->client(), $store->client());
    }

    /**
     * The recorded statement with its heredoc indentation and line breaks collapsed.
     */
    protected function statement(int $run): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($this->client->runs[$run]['statement']));
    }

    /**
     * @param string[] $labels
     * @param string[] $relationshipTypes
     * @return array{nodes: CypherList, relationships: CypherList}
     */
    protected function schemaVisualization(array $labels, array $relationshipTypes): array
    {
        return [
            'nodes' => new CypherList(array_map(
                static fn (string $label): Node => new Node(0, new CypherList([$label]), new CypherMap([]), null),
                $labels
            )),
            'relationships' => new CypherList(array_map(
                static fn (string $type): Relationship => new Relationship(0, 0, 0, $type, new CypherMap([]), null),
                $relationshipTypes
            )),
        ];
    }
}
