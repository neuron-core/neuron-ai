<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\AWS;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A real BedrockRuntimeClient validates the input against the Converse API
 * model before the (mocked) transport is reached, which a mocked client skips.
 */
class BedrockSdkValidationTest extends TestCase
{
    protected ?CommandInterface $sent = null;

    protected function client(): BedrockRuntimeClient
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $command): Result {
            $this->sent = $command;
            return new Result([
                'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
                'stopReason' => 'end_turn',
            ]);
        });

        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'AKIDEXAMPLE', 'secret' => 'secret'],
            'handler' => $handler,
        ]);
    }

    public function test_system_prompt_is_sent_when_set(): void
    {
        $provider = (new BedrockRuntime($this->client(), 'model'))->systemPrompt('sys');

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame([['text' => 'sys']], $this->sent['system']);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function absentSystemPrompts(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    #[DataProvider('absentSystemPrompts')]
    public function test_an_absent_system_prompt_is_left_out(?string $prompt): void
    {
        $provider = (new BedrockRuntime($this->client(), 'model'))->systemPrompt($prompt);

        $this->assertSame('ok', $provider->chat(new UserMessage('Hi'))->message()->getContent());
        $this->assertArrayNotHasKey('system', $this->sent->toArray());
    }

    public function test_a_tool_with_an_empty_description_is_accepted(): void
    {
        $provider = (new BedrockRuntime($this->client(), 'model'))->setTools([new ToolStub('lookup', '')]);

        $this->assertSame('ok', $provider->chat(new UserMessage('Hi'))->message()->getContent());
        $this->assertArrayNotHasKey('description', $this->sent['toolConfig']['tools'][0]['toolSpec']);
    }

    public function test_a_tool_description_is_sent_when_set(): void
    {
        $provider = (new BedrockRuntime($this->client(), 'model'))->setTools([new ToolStub('lookup', 'Look up an order')]);

        $provider->chat(new UserMessage('Hi'));

        $this->assertSame('Look up an order', $this->sent['toolConfig']['tools'][0]['toolSpec']['description']);
    }

    public function test_tool_without_description_is_accepted(): void
    {
        $provider = (new BedrockRuntime($this->client(), 'model'))
            ->systemPrompt('sys')
            ->setTools([new ToolStub('lookup')]);

        $this->assertSame('ok', $provider->chat(new UserMessage('Hi'))->message()->getContent());
        $this->assertArrayNotHasKey('description', $this->sent['toolConfig']['tools'][0]['toolSpec']);
    }
}
