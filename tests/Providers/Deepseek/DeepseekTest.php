<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Deepseek;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\Deepseek\MessageMapper;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

class DeepseekTest extends TestCase
{
    use RecordsHttpRequests;

    protected const ANSWER = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"{\"name\":\"Ada\"}"}}]}';

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_chat_exposes_reasoning_content_as_block_and_metadata(): void
    {
        $response = json_encode(['choices' => [[
            'index' => 0,
            'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => 'Answer', 'reasoning_content' => 'Thinking'],
        ]]], JSON_THROW_ON_ERROR);
        $provider = new Deepseek('sk-test', 'deepseek-reasoner', httpClient: $this->recordingClient(new Response(200, body: $response)));

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertSame(['POST https://api.deepseek.com/v1/chat/completions'], $this->sentTargets());
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Answer', $message->getContent());
        $this->assertSame('Thinking', $message->getMetadata('reasoning_content'));
        $this->assertInstanceOf(ReasoningContent::class, $message->getReasoning());
        $this->assertSame('Thinking', $message->getReasoning()->content);
    }

    public function test_mapper_sends_reasoning_content_back_for_assistant_and_tool_call_turns(): void
    {
        $answer = (new AssistantMessage('Answer'))->addMetadata('reasoning_content', 'Thought A');
        $toolCall = (new ToolCallMessage(null, [new ToolCall('clock', 'call_1', [])]))->addMetadata('reasoning_content', 'Thought B');
        $plain = new AssistantMessage('No reasoning');

        $mapped = (new MessageMapper())->map([$answer, $toolCall, $plain]);

        $this->assertSame('Thought A', $mapped[0]['reasoning_content']);
        $this->assertSame('Thought B', $mapped[1]['reasoning_content']);
        $this->assertArrayNotHasKey('reasoning_content', $mapped[2]);
    }

    public function test_reasoning_blocks_are_not_sent_as_message_content(): void
    {
        $message = new AssistantMessage([new ReasoningContent('Thinking'), new TextContent('Answer')]);

        $this->assertSame([['type' => 'text', 'text' => 'Answer']], (new MessageMapper())->map([$message])[0]['content']);
    }

    public function test_structured_requests_json_object_and_appends_the_schema_to_the_system_prompt(): void
    {
        $provider = new Deepseek('sk-test', 'deepseek-chat', ['temperature' => 0], httpClient: $this->recordingClient(new Response(200, body: self::ANSWER)));
        $provider->systemPrompt('Be precise');
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $provider->structured(new UserMessage('Who?'), 'Person', $schema);

        $body = $this->sentBody();
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
        $this->assertSame(0, $body['temperature']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame(
            'Be precise'.PHP_EOL.'# OUTPUT FORMAT CONSTRAINTS'.PHP_EOL.'Generate a json respecting this schema: '.json_encode($schema),
            $body['messages'][0]['content'][0]['text'],
        );
    }

    public function test_structured_restores_system_prompt_and_parameters_even_when_the_request_fails(): void
    {
        $provider = new Deepseek('sk-test', 'deepseek-chat', httpClient: $this->recordingClient(
            new Response(500, body: '{"error":{"message":"boom"}}'),
            new Response(200, body: self::ANSWER),
        ));
        $provider->systemPrompt('Be precise');

        try {
            $provider->structured([new UserMessage('Who?')], 'Person', ['type' => 'object']);
            $this->fail('The failing request must surface.');
        } catch (HttpException) {
        }
        $provider->chat(new UserMessage('Hi'));

        $body = $this->sentBody(1);
        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertSame([['type' => 'text', 'text' => 'Be precise']], $body['messages'][0]['content']);
    }
}
