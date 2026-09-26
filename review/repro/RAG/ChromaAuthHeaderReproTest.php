<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;

class ChromaAuthHeaderReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    public function test_token_is_sent_in_the_authorization_header(): void
    {
        new ChromaVectorStore(
            collection: 'docs',
            key: 'chroma-token',
            httpClient: $this->recordingClient($this->jsonResponse(['id' => 'col-uuid'])),
        );

        $this->assertSame('Bearer chroma-token', $this->sentRequest(0)->getHeaderLine('Authorization'));
        $this->assertFalse($this->sentRequest(0)->hasHeader('Authentication'));
    }

    public function test_no_authorization_header_without_a_key(): void
    {
        new ChromaVectorStore(
            collection: 'docs',
            httpClient: $this->recordingClient($this->jsonResponse(['id' => 'col-uuid'])),
        );

        $this->assertFalse($this->sentRequest(0)->hasHeader('Authorization'));
    }
}
