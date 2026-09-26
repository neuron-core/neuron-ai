<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\GraphStore\Stub;

use Laudis\Neo4j\Contracts\ClientInterface;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;

/**
 * A Neo4j graph store whose lazily built client has already been resolved to the given one.
 */
class Neo4jGraphStoreWithClient extends Neo4jGraphStore
{
    public function __construct(ClientInterface $client, string $nodeLabel = 'Entity')
    {
        parent::__construct(nodeLabel: $nodeLabel);
        $this->client = $client;
    }
}
