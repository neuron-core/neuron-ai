<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\MCP\StreamableHttpTransport;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

use function count;
use function iterator_to_array;

class ConsumerIsolationTest extends TestCase
{
    public function test_shared_client_keeps_each_consumers_destination_and_credentials(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"content":[{"type":"text","text":"Hi"}],"stop_reason":"end_turn"}'),
            new Response(200, [], '{"choices":[{"finish_reason":"stop","message":{"content":"Hi"}}]}'),
            new Response(200, [], '{"data":[{"embedding":[0.5]}]}'),
            new Response(200, [], '{"matches":[]}'),
            new Response(200, [], '{"results":[]}'),
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
        ]));
        $stack->push(Middleware::history($sent));
        $client = (new GuzzleHttpClient(customHeaders: ['X-Application' => 'retained'], timeout: 90, handler: $stack))
            ->withBaseUri('https://application.example');

        $anthropic = new Anthropic('anthropic-secret', 'model', httpClient: $client);
        $openai = new OpenAI('openai-secret', 'model', httpClient: $client);
        $embeddings = new OpenAIEmbeddingsProvider('embedding-secret', 'model', httpClient: $client);
        $store = new PineconeVectorStore('pinecone-secret', 'https://index.example', httpClient: $client);
        $reranker = new CohereRerankerPostProcessor('reranker-secret', httpClient: $client);
        $mcp = new StreamableHttpTransport(['url' => 'https://mcp.example', 'token' => 'mcp-secret', 'timeout' => 15], $client);

        $anthropic->chat(new UserMessage('Hi'));
        $openai->chat(new UserMessage('Hi'));
        $embeddings->embedText('Hi');
        $store->search(new SearchRequest([0.5]));
        $reranker->process(new UserMessage('Hi'), []);
        $mcp->send(['jsonrpc' => '2.0', 'method' => 'test']);
        $client->request(HttpRequest::get('health'));

        $expected = [
            ['https://api.anthropic.com/v1/messages', ['x-api-key' => 'anthropic-secret']],
            ['https://api.openai.com/v1/chat/completions', ['Authorization' => 'Bearer openai-secret']],
            ['https://api.openai.com/v1/embeddings', ['Authorization' => 'Bearer embedding-secret']],
            ['https://index.example/query', ['Api-Key' => 'pinecone-secret']],
            ['https://api.cohere.com/v2/rerank', ['Authorization' => 'Bearer reranker-secret']],
            ['https://mcp.example', ['Authorization' => 'Bearer mcp-secret']],
            ['https://application.example/health', []],
        ];

        self::assertCount(count($expected), $sent);
        foreach ($expected as $index => [$uri, $credentials]) {
            $request = $sent[$index]['request'];
            self::assertSame($uri, (string) $request->getUri());
            self::assertSame('retained', $request->getHeaderLine('X-Application'));
            foreach (['Authorization', 'x-api-key', 'Api-Key'] as $header) {
                self::assertSame($credentials[$header] ?? '', $request->getHeaderLine($header));
            }
            self::assertSame($index === 5 ? 15.0 : 90.0, $sent[$index]['options']['timeout']);
        }
    }

    public function test_replacing_client_preserves_configuration_for_chat_and_stream(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"choices":[{"finish_reason":"stop","message":{"content":"Hi"}}]}'),
            new Response(200, [], "data: [DONE]\n\n"),
        ]));
        $stack->push(Middleware::history($sent));
        $client = (new GuzzleHttpClient(handler: $stack))->withBaseUri('https://unused.example');
        $provider = (new OpenAI('provider-secret', 'model'))->setHttpClient($client);

        $provider->chat(new UserMessage('Hi'));
        iterator_to_array($provider->stream(new UserMessage('Hi')));

        self::assertCount(2, $sent);
        foreach ($sent as $entry) {
            self::assertSame('https://api.openai.com/v1/chat/completions', (string) $entry['request']->getUri());
            self::assertSame('Bearer provider-secret', $entry['request']->getHeaderLine('Authorization'));
        }
    }
}
