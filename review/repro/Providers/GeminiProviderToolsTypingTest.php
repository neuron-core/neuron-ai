<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tools\ProviderTool;
use PHPUnit\Framework\TestCase;

/**
 * Passes under PHPUnit at runtime. The repro is static:
 * `vendor/bin/phpstan analyse <this file>` reports argument.type on the setTools() line
 * because AIProviderInterface::setTools() documents ToolInterface[] only.
 */
class GeminiProviderToolsTypingTest extends TestCase
{
    public function test_provider_tools_can_be_passed_directly_to_set_tools(): void
    {
        $sentRequests = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"candidates":[{"content":{"role":"model","parts":[{"text":"ok"}]},"finishReason":"STOP"}]}'),
        ]));
        $stack->push(Middleware::history($sentRequests));

        $provider = (new Gemini('key', 'gemini-2.0-flash'))->setHttpClient(new GuzzleHttpClient(handler: $stack));
        $provider->setTools([new ProviderTool('google_search')]);

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(
            '{"contents":[{"role":"user","parts":[{"text":"Hi"}]}],"tools":[{"google_search":{}}]}',
            (string) $sentRequests[0]['request']->getBody(),
        );
    }
}
