<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Gemini\Gemini;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;
use function microtime;
use function str_repeat;

class GeminiStreamPerformanceTest extends TestCase
{
    protected function provider(string $body): Gemini
    {
        return new Gemini('key', 'gemini-2.5-flash', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        ));
    }

    public function test_large_inline_data_is_parsed_in_linear_time(): void
    {
        $data = str_repeat('A', 200_000);
        $stream = $this->provider(
            '[{"candidates":[{"content":{"parts":[{"inlineData":{"mimeType":"image/png","data":"'.$data.'"}}]},"finishReason":"STOP"}]}]'
        )->stream(new UserMessage('Draw'));

        $start = microtime(true);
        iterator_to_array($stream);

        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertSame($data, $stream->getReturn()->message()->getImage()?->content);
    }

    public function test_braces_and_escaped_quotes_inside_strings_do_not_break_element_framing(): void
    {
        $stream = $this->provider(
            '[{"candidates":[{"content":{"parts":[{"text":"a } \\" { b"}]}}]}'."\r\n,".
            '{"candidates":[{"content":{"parts":[{"text":"c\\\\"}]},"finishReason":"STOP"}]}]'
        )->stream(new UserMessage('Hi'));

        $chunks = array_map(fn (TextChunk $chunk): string => $chunk->content, iterator_to_array($stream, false));

        $this->assertSame(['a } " { b', 'c\\'], $chunks);
        $this->assertSame('a } " { bc\\', $stream->getReturn()->message()->getContent());
    }
}
