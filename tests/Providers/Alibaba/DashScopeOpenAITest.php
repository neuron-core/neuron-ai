<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Alibaba;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Alibaba\DashScopeOpenAI;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

class DashScopeOpenAITest extends TestCase
{
    use RecordsHttpRequests;

    protected function response(array $message): Response
    {
        return new Response(200, body: json_encode(['choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => $message]]], JSON_THROW_ON_ERROR));
    }

    public function test_chat_targets_the_compatible_mode_endpoint_by_default(): void
    {
        $provider = new DashScopeOpenAI('sk-test', 'qwen-plus', httpClient: $this->recordingClient($this->response(['role' => 'assistant', 'content' => 'Hi'])));

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(['POST https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions'], $this->sentTargets());
        $this->assertSame('Bearer sk-test', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_regional_base_uri_can_be_configured(): void
    {
        $provider = new DashScopeOpenAI(
            key: 'sk-test',
            model: 'qwen-plus',
            httpClient: $this->recordingClient($this->response(['role' => 'assistant', 'content' => 'Hi'])),
            baseUri: 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/',
        );

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(['POST https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions'], $this->sentTargets());
    }

    public function test_chat_reasoning_content_becomes_a_reasoning_block(): void
    {
        $provider = new DashScopeOpenAI('sk-test', 'qwen3', httpClient: $this->recordingClient(
            $this->response(['role' => 'assistant', 'content' => 'Answer', 'reasoning_content' => 'Thinking']),
        ));

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertSame('Answer', $message->getContent());
        $this->assertInstanceOf(ReasoningContent::class, $message->getReasoning());
        $this->assertSame('Thinking', $message->getReasoning()->content);
    }

    public function test_chat_without_reasoning_has_no_reasoning_block(): void
    {
        $provider = new DashScopeOpenAI('sk-test', 'qwen-plus', httpClient: $this->recordingClient(
            $this->response(['role' => 'assistant', 'content' => 'Answer']),
        ));

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertNull($message->getReasoning());
        $this->assertCount(1, $message->getContentBlocks());
    }
}
