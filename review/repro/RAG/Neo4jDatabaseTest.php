<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\GraphStore;

use Laudis\Neo4j\Client;
use NeuronAI\RAG\GraphStore\Neo4jGraphStore;
use PHPUnit\Framework\TestCase;

class Neo4jDatabaseTest extends TestCase
{
    public function test_the_configured_database_is_used_by_the_client_sessions(): void
    {
        $client = (new Neo4jGraphStore(database: 'knowledge'))->client();

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('knowledge', $client->getDefaultSessionConfiguration()->getDatabase());
    }
}
