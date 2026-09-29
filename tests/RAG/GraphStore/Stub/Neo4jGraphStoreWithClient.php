<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\GraphStore\Stub;

use Laudis\Neo4j\Contracts\ClientInterface;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;

/**
 * A Neo4j graph store that supplies the given client through the client() hook, as a subclass would.
 */
class Neo4jGraphStoreWithClient extends Neo4jGraphStore
{
    public function __construct(protected ClientInterface $suppliedClient, string $nodeLabel = 'Entity')
    {
        parent::__construct(nodeLabel: $nodeLabel);
    }

    public function client(): ClientInterface
    {
        return $this->suppliedClient;
    }
}
