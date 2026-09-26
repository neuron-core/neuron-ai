<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\GraphStore;

use Laudis\Neo4j\Contracts\ClientInterface;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use NeuronAI\Tests\RAG\GraphStore\Stub\RecordingNeo4jClient;
use PHPUnit\Framework\TestCase;

class Neo4jRelationshipMapClientTest extends TestCase
{
    public function test_relationship_map_resolves_the_client_through_the_lazy_client_hook(): void
    {
        $recording = new RecordingNeo4jClient();
        $store = new class ($recording) extends Neo4jGraphStore {
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
        $this->assertCount(1, $recording->runs);
        $this->assertSame(['subjects' => ['Alice']], $recording->runs[0]['parameters']);
    }
}
