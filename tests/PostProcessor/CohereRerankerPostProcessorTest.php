<?php

declare(strict_types=1);

namespace NeuronAI\Tests\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

use function json_encode;

class CohereRerankerPostProcessorTest extends TestCase
{
    public function test_post_process_reranks_documents(): void
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
                    ],
                    'meta' => [
                        'api_version' => [
                            'version' => '2',
                            'is_experimental' => false
                        ],
                        'billed_units' => [
                            'search_units' => 1
                        ]
                    ],
                    'id' => '07734bd2-2473-4f07-94e1-0d9f0e6843cf'
                ])
            )
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $postProcessor = (new CohereRerankerPostProcessor(key: '', httpClient: new GuzzleHttpClient(handler: $stack)));

        $question = new UserMessage("What is the capital of Italy?");
        $documents = [
            new Document("Paris is the capital of France"),
            new Document("Rome is the capital of Italy"),
            new Document("Madrid is the capital of Spain"),
            new Document("London is the capital of the United Kingdom")
        ];

        $result = $postProcessor->process($question, $documents);

        $this->assertCount(3, $result, "Cohere API returns 3 results by default");
        $this->assertEquals("Rome is the capital of Italy", $result[0]->getContent(), "Rome should be the first result");
        $this->assertEquals("Paris is the capital of France", $result[1]->getContent(), "Paris should be the second result");
        $this->assertEquals("Madrid is the capital of Spain", $result[2]->getContent(), "Madrid should be the third result");

        $this->assertEquals(0.9, $result[0]->getScore(), "Score should match the mock response");
    }

    public function test_post_process_with_top_n_parameter(): void
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
                    ],
                    'meta' => [
                        'api_version' => [
                            'version' => '2',
                            'is_experimental' => false
                        ],
                        'billed_units' => [
                            'search_units' => 1
                        ]
                    ],
                    'id' => '07734bd2-2473-4f07-94e1-0d9f0e6843cf'
                ])
            )
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $postProcessor = (new CohereRerankerPostProcessor(
            key: '',
            model: 'rerank-v3.5',
            topN: 2,
            httpClient: new GuzzleHttpClient(handler: $stack)
        ));

        $question = new UserMessage("What is the capital of Italy?");
        $documents = [
            new Document("Paris is the capital of France"),
            new Document("Rome is the capital of Italy"),
            new Document("Madrid is the capital of Spain"),
            new Document("London is the capital of the United Kingdom")
        ];

        $result = $postProcessor->process($question, $documents);

        $this->assertCount(2, $result, "Cohere API returns exactly top_n results");
        $this->assertEquals("Rome is the capital of Italy", $result[0]->getContent(), "Rome should be the first result");
        $this->assertEquals("Paris is the capital of France", $result[1]->getContent(), "Paris should be the second result");
    }
}
