<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\GraphStore;

use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Types\CypherList;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use NeuronAI\RAG\GraphStore\Triplet;
use NeuronAI\Tests\RAG\GraphStore\Stub\Neo4jGraphStoreWithClient;
use NeuronAI\Tests\RAG\GraphStore\Stub\RecordingNeo4jClient;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function in_array;
use function iterator_to_array;
use function preg_match;
use function serialize;
use function trim;

/**
 * Evaluates the relationship-map Cypher template the way Neo4j would, over an in-memory graph:
 * enumerate every path of length 1..depth from each subject, unwind its relationships,
 * project each one with the expressions inside collect([...]) and group the result by subject.
 */
class GraphEvaluatingNeo4jClient extends RecordingNeo4jClient
{
    /**
     * @param list<array{0: string, 1: string, 2: string}> $edges [start, type, end]
     */
    public function __construct(protected array $edges)
    {
    }

    public function run(string $statement, iterable $parameters = [], ?string $alias = null): SummarizedResult
    {
        $parameters = iterator_to_array($parameters);
        preg_match('/\[\*1\.\.(\d+)\]/', $statement, $depth);
        preg_match('/collect\((DISTINCT )?\[([^\]]*)\]\)/', $statement, $collect);
        $distinct = $collect[1] !== '';
        $projection = array_map(trim(...), explode(',', $collect[2]));

        $rows = [];
        foreach ($parameters['subjects'] as $subject) {
            $rels = [];
            foreach ($this->paths($subject, (int) $depth[1]) as $path) {
                foreach ($path as [$start, $type, $end]) {
                    $rels[] = array_map(static fn (string $expression): string => match ($expression) {
                        'startNode(rel).id' => $start,
                        'type(rel)' => $type,
                        'endNode(rel).id' => $end,
                    }, $projection);
                }
            }
            if ($distinct) {
                $rels = array_values(array_map('unserialize', array_unique(array_map(serialize(...), $rels))));
            }
            if ($rels !== []) {
                $rows[] = ['subject' => $subject, 'rels' => new CypherList(array_map(static fn (array $rel): CypherList => new CypherList($rel), $rels))];
            }
        }

        $this->willReturn($rows);

        return parent::run($statement, $parameters, $alias);
    }

    /**
     * @return list<list<array{0: string, 1: string, 2: string}>>
     */
    protected function paths(string $from, int $depth, array $prefix = []): array
    {
        if ($depth === 0) {
            return [];
        }
        $paths = [];
        foreach ($this->edges as $edge) {
            if ($edge[0] !== $from || in_array($edge, $prefix, true)) {
                continue;
            }
            $path = [...$prefix, $edge];
            $paths[] = $path;
            $paths = [...$paths, ...$this->paths($edge[2], $depth - 1, $path)];
        }

        return $paths;
    }
}

class Neo4jGraphStoreRelationshipMapTest extends TestCase
{
    public function test_relationship_map_keeps_the_real_start_node_of_every_hop_without_duplicates(): void
    {
        $client = new GraphEvaluatingNeo4jClient([
            ['Alice', 'KNOWS', 'Bob'],
            ['Bob', 'KNOWS', 'Charlie'],
            ['Charlie', 'KNOWS', 'Dave'],
        ]);
        $store = new Neo4jGraphStoreWithClient($client);

        $map = $store->getRelationshipMap(['Alice'], depth: 2);

        $this->assertSame(
            [['Alice', 'KNOWS', 'Bob'], ['Bob', 'KNOWS', 'Charlie']],
            array_map(static fn (Triplet $triplet): array => $triplet->toArray(), $map['Alice'])
        );
    }

    public function test_relationship_map_resolves_the_lazily_built_client(): void
    {
        $client = new RecordingNeo4jClient();
        $store = new class ($client) extends Neo4jGraphStore {
            public function __construct(protected ClientInterface $recording)
            {
                parent::__construct();
            }

            public function client(): ClientInterface
            {
                return $this->recording;
            }
        };

        $this->assertSame([], $store->getRelationshipMap(['Alice']));
        $this->assertCount(1, $client->runs);
    }
}
