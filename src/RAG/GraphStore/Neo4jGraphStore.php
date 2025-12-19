<?php

declare(strict_types=1);

namespace NeuronAI\RAG\GraphStore;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Exception;

use function str_replace;
use function strtoupper;
use function array_map;
use function implode;

class Neo4jGraphStore implements GraphStoreInterface
{
    protected ClientInterface $client;
    protected ?string $cachedSchema = null;

    public function __construct(
        protected string $uri = 'bolt://localhost:7687',
        protected string $username = '',
        protected string $password = '',
        protected string $database = 'neo4j',
        protected string $nodeLabel = 'Entity',
    ) {
    }

    public function upsert(string $subject, string $relation, string $object): void
    {
        // Normalize relationship type: spaces to underscores, uppercase
        $relationshipType = strtoupper(str_replace(' ', '_', $relation));

        $query = <<<CYPHER
        MERGE (n1:`{$this->nodeLabel}` {id: \$subject})
        MERGE (n2:`{$this->nodeLabel}` {id: \$object})
        MERGE (n1)-[r:`{$relationshipType}`]->(n2)
        CYPHER;

        $this->client()->run($query, [
            'subject' => $subject,
            'object' => $object,
        ]);

        // Invalidate schema cache
        $this->cachedSchema = null;
    }

    public function delete(string $subject, string $relation, string $object): void
    {
        $relationshipType = strtoupper(str_replace(' ', '_', $relation));

        // Delete the specific relationship
        $query = <<<CYPHER
        MATCH (n1:`{$this->nodeLabel}`)-[r:`{$relationshipType}`]->(n2:`{$this->nodeLabel}`)
        WHERE n1.id = \$subject AND n2.id = \$object
        DELETE r
        CYPHER;

        $this->client()->run($query, [
            'subject' => $subject,
            'object' => $object,
        ]);

        // Clean up isolated nodes (nodes with no relationships)
        $cleanupQuery = <<<CYPHER
        MATCH (n:`{$this->nodeLabel}`)
        WHERE n.id IN [\$subject, \$object]
        AND NOT (n)-[]-()
        DELETE n
        CYPHER;

        $this->client()->run($cleanupQuery, [
            'subject' => $subject,
            'object' => $object,
        ]);

        // Invalidate schema cache
        $this->cachedSchema = null;
    }

    public function get(string $subject): array
    {
        $query = <<<CYPHER
        MATCH (n1:`{$this->nodeLabel}`)-[r]->(n2:`{$this->nodeLabel}`)
        WHERE n1.id = \$subject
        RETURN type(r) AS relation, n2.id AS object
        CYPHER;

        $result = $this->client()->run($query, ['subject' => $subject]);

        $triplets = [];
        foreach ($result as $record) {
            $triplets[] = [
                $subject,
                $record->get('relation'),
                $record->get('object'),
            ];
        }

        return $triplets;
    }

    public function getRelationshipMap(array $subjects = [], int $depth = 2, int $limit = 30): array
    {
        if ($subjects === []) {
            return [];
        }

        $query = <<<CYPHER
        MATCH path = (n1:`{$this->nodeLabel}`)-[*1..{$depth}]->(n2:`{$this->nodeLabel}`)
        WHERE n1.id IN \$subjects
        UNWIND relationships(path) AS rel
        WITH n1.id AS subject, collect([type(rel), endNode(rel).id]) AS rels
        RETURN subject, rels
        LIMIT {$limit}
        CYPHER;

        $result = $this->client->run($query, ['subjects' => $subjects]);

        $relationshipMap = [];
        foreach ($result as $record) {
            $subject = $record->get('subject');
            $rels = $record->get('rels');

            $triplets = [];
            foreach ($rels as $rel) {
                // Each rel is [relationship_type, end_node_id]
                $triplets[] = [
                    $subject,
                    $rel[0], // relationship type
                    $rel[1], // end node id
                ];
            }

            $relationshipMap[$subject] = $triplets;
        }

        return $relationshipMap;
    }

    public function getSchema(bool $refresh = false): string
    {
        if (!$refresh && $this->cachedSchema !== null) {
            return $this->cachedSchema;
        }

        // Get all relationship types and node labels
        $query = <<<CYPHER
        CALL db.schema.visualization()
        YIELD nodes, relationships
        RETURN nodes, relationships
        CYPHER;

        try {
            $result = $this->client()->run($query);
            $record = $result->first();

            $nodes = $record->get('nodes');
            $relationships = $record->get('relationships');

            $schema = "Node Labels:\n";
            foreach ($nodes as $node) {
                $labels = $node->getLabels();
                foreach ($labels as $label) {
                    $schema .= "  - {$label}\n";
                }
            }

            $schema .= "\nRelationship Types:\n";
            foreach ($relationships as $rel) {
                $type = $rel->getType();
                $schema .= "  - {$type}\n";
            }

            $this->cachedSchema = $schema;
            return $schema;
        } catch (Exception) {
            // Fallback to simpler schema query
            return $this->getSimpleSchema();
        }
    }

    protected function getSimpleSchema(): string
    {
        $labelQuery = "CALL db.labels()";
        $relTypeQuery = "CALL db.relationshipTypes()";

        $labels = $this->client()->run($labelQuery);
        $relTypes = $this->client()->run($relTypeQuery);

        $schema = "Node Labels:\n";
        foreach ($labels as $record) {
            $schema .= "  - {$record->get('label')}\n";
        }

        $schema .= "\nRelationship Types:\n";
        foreach ($relTypes as $record) {
            $schema .= "  - {$record->get('relationshipType')}\n";
        }

        $this->cachedSchema = $schema;
        return $schema;
    }

    public function query(string $query, array $parameters = []): mixed
    {
        $result = $this->client()->run($query, $parameters);

        $records = [];
        foreach ($result as $record) {
            $records[] = $record->toArray();
        }

        return $records;
    }

    public function client(): ClientInterface
    {
        return $this->client ?? $this->client = ClientBuilder::create()
            ->withDriver('default', $this->uri, Authenticate::basic($this->username, $this->password))
            ->withDefaultDriver('default')
            ->build();
    }
}
