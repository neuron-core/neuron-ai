<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\ZAI\Image\ZAIImage;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

/**
 * Providers receive the conversation's own message objects (StructuredOutputNode
 * commits the inbound messages to chat history after the provider call), so a
 * provider must never rewrite them.
 */
class CallerMessageMutationTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    protected array $sent = [];

    protected function client(string $body): GuzzleHttpClient
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, body: $body),
            new Response(200, body: $body),
        ]));
        $stack->push(Middleware::history($this->sent));

        return new GuzzleHttpClient(handler: $stack);
    }

    protected function imageQuestion(): UserMessage
    {
        $question = new UserMessage([new ImageContent('iVBORw0=', SourceType::BASE64, 'image/png')]);
        $question->addContent(new TextContent('Who is this?'));

        return $question;
    }

    public function test_gemini_structured_output_keeps_the_callers_message(): void
    {
        $provider = new Gemini('key', 'gemini-2.0-flash', httpClient: $this->client('{"candidates":[{"content":{"parts":[{"text":"{}"}]},"finishReason":"STOP"}]}'));
        $provider->setTools([new ToolStub('lookup')]);
        $question = $this->imageQuestion();

        $provider->structured([$question], 'Person', ['type' => 'object']);
        $provider->structured([$question], 'Person', ['type' => 'object']);

        $this->assertSame('Who is this?', $question->getContent());
        $this->assertNotNull($question->getImage());
    }

    public function test_gemini_structured_output_sends_the_users_image(): void
    {
        $provider = new Gemini('key', 'gemini-2.0-flash', httpClient: $this->client('{"candidates":[{"content":{"parts":[{"text":"{}"}]},"finishReason":"STOP"}]}'));
        $provider->setTools([new ToolStub('lookup')]);

        $provider->structured([$this->imageQuestion()], 'Person', ['type' => 'object']);

        $payload = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertStringContainsString('inline_data', (string) json_encode($payload['contents'][0]['parts']));
    }

    public function test_cohere_structured_output_keeps_the_callers_message(): void
    {
        $provider = new Cohere('key', 'command-a', httpClient: $this->client('{"finish_reason":"COMPLETE","message":{"role":"assistant","content":[{"type":"text","text":"{}"}]}}'));
        $question = new UserMessage('Who?');

        $provider->structured([$question], 'Person', ['type' => 'object']);
        $provider->structured([$question], 'Person', ['type' => 'object']);

        $this->assertSame('Who?', $question->getContent());
    }

    public function test_agent_structured_output_history_keeps_the_users_words(): void
    {
        $agent = Agent::make()
            ->setAiProvider(new Cohere('key', 'command-a', httpClient: $this->client('{"finish_reason":"COMPLETE","message":{"role":"assistant","content":[{"type":"text","text":"{\"name\":\"Ada\"}"}]}}')))
            ->setMessageStore(new InMemoryMessageStore())
            ->setThreadId('t1');

        $agent->structured(new UserMessage('Who?'), User::class);

        $this->assertSame('Who?', $agent->getChatHistory()->getMessages()[0]->getContent());
    }

    public function test_zai_image_generation_keeps_the_callers_message(): void
    {
        $provider = new ZAIImage('key', 'cogview-4', httpClient: $this->client('{"data":[{"url":"https://cdn.z.ai/x.png"}]}'));
        $provider->systemPrompt('Photorealistic style');
        $prompt = new UserMessage('A red fox');

        $provider->chat($prompt);
        $provider->chat($prompt);

        $this->assertSame('A red fox', $prompt->getContent());
    }
}
