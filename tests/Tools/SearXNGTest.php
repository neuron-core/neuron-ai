<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Tools\Toolkits\SearXNG\SearXNGSearch;
use NeuronAI\Tools\Toolkits\SearXNG\SearXNGToolkit;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SearXNGTest extends TestCase
{
    public function test_searxng_search_tool(): void
    {
        $mockResults = [
            'results' => [
                [
                    'title' => 'Test Result 1',
                    'url' => 'https://example.com/1',
                    'content' => 'Content 1',
                ],
                [
                    'title' => 'Test Result 2',
                    'url' => 'https://example.com/2',
                    'content' => 'Content 2',
                ],
            ],
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResults)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $tool = new SearXNGSearch('https://searx.be');
        
        // Inject the mock client
        $reflection = new ReflectionClass($tool);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($tool, $client);

        $result = $tool->__invoke('php neuron-ai');

        $this->assertArrayHasKey('results', $result);
        $this->assertCount(2, $result['results']);
        $this->assertEquals('Test Result 1', $result['results'][0]['title']);
        $this->assertEquals('https://example.com/1', $result['results'][0]['url']);
        $this->assertEquals('Content 1', $result['results'][0]['content']);
    }

    public function test_searxng_toolkit(): void
    {
        $toolkit = new SearXNGToolkit('https://searx.be');
        $tools = $toolkit->provide();

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(SearXNGSearch::class, $tools[0]);
    }
}
