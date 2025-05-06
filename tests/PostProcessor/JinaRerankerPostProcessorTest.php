<?php

namespace NeuronAI\Tests\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class JinaRerankerPostProcessorTest extends TestCase
{
    public function test_post_process_reranks_documents()
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: json_encode([
                    'results' => [
                        ['index' => 1, 'relevance_score' => 0.9],
                        ['index' => 0, 'relevance_score' => 0.2],
                        ['index' => 2, 'relevance_score' => 0.1]
                    ]
                ])
            )
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $postProcessor = (new JinaRerankerPostProcessor(''))->setClient($client);

        $question = new UserMessage("What is the capital of Italy?");
        $documents = [
            new Document("Paris is the capital of France"),
            new Document("Rome is the capital of Italy"),
            new Document("Madrid is the capital of Spain"),
            new Document("London is the capital of the United Kingdom")
        ];

        $result = $postProcessor->process($question, $documents);

        $this->assertCount(3, $result, "Jina API returns 3 results by default");
        $this->assertEquals("Rome is the capital of Italy", $result[0]->content, "Rome should be the first result");
        $this->assertEquals("Paris is the capital of France", $result[1]->content, "Paris should be the second result");
        $this->assertEquals("Madrid is the capital of Spain", $result[2]->content, "Madrid should be the third result");
    }

    public function test_post_process_with_top_n_parameter()
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: json_encode([
                    'results' => [
                        ['index' => 1, 'relevance_score' => 0.9],
                        ['index' => 0, 'relevance_score' => 0.2]
                    ]
                ])
            )
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $postProcessor = (new JinaRerankerPostProcessor('', 'jina-reranker-v2-base-multilingual', 2))->setClient($client);

        $question = new UserMessage("What is the capital of Italy?");
        $documents = [
            new Document("Paris is the capital of France"),
            new Document("Rome is the capital of Italy"),
            new Document("Madrid is the capital of Spain"),
            new Document("London is the capital of the United Kingdom")
        ];

        $result = $postProcessor->process($question, $documents);

        $this->assertCount(2, $result, "Jina API returns exactly top_n results");
        $this->assertEquals("Rome is the capital of Italy", $result[0]->content, "Rome should be the first result");
        $this->assertEquals("Paris is the capital of France", $result[1]->content, "Paris should be the second result");
    }
}
