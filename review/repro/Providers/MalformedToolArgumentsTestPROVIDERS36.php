<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\ZAI\ZAI;
use NeuronAI\Tests\Providers\AWS\Stub\BedrockStreamClient;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function iterator_to_array;

use const JSON_THROW_ON_ERROR;

class MalformedToolArgumentsTestPROVIDERS36 extends TestCase
{
    use RecordsHttpRequests;

    protected function mistral(string $arguments): Mistral
    {
        $provider = new Mistral('key', 'mistral-large-latest', httpClient: $this->recordingClient(
            new Response(200, body: self::toolCallCompletion($arguments)),
        ));
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        return $provider;
    }

    protected function zai(string $arguments): ZAI
    {
        $provider = new ZAI('key', 'glm-4.6', httpClient: $this->recordingClient(
            new Response(200, body: self::toolCallCompletion($arguments)),
        ));
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        return $provider;
    }

    protected function bedrock(string $input): BedrockRuntime
    {
        $provider = new BedrockRuntime(new BedrockStreamClient([
            ['contentBlockStart' => ['contentBlockIndex' => 0, 'start' => ['toolUse' => ['name' => 'lookup', 'toolUseId' => 'id-1']]]],
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['toolUse' => ['input' => $input]]]],
            ['contentBlockStop' => ['contentBlockIndex' => 0]],
            ['messageStop' => ['stopReason' => 'tool_use']],
        ]), 'model');
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        return $provider;
    }

    protected static function toolCallCompletion(string $arguments): string
    {
        return json_encode([
            'id' => 'cmpl-1',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => $arguments]]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedArguments(): array
    {
        return [
            'truncated object' => ['{"q":"par'],
            'not json' => ['q=paris'],
            'json scalar' => ['"paris"'],
            'json number' => ['42'],
        ];
    }

    #[DataProvider('malformedArguments')]
    public function test_mistral_rejects_malformed_tool_arguments_instead_of_running_the_tool_without_inputs(string $arguments): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('lookup');

        $this->mistral($arguments)->chat(new UserMessage('Where?'));
    }

    #[DataProvider('malformedArguments')]
    public function test_openai_compatible_providers_reject_malformed_tool_arguments(string $arguments): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('lookup');

        $this->zai($arguments)->chat(new UserMessage('Where?'));
    }

    #[DataProvider('malformedArguments')]
    public function test_bedrock_stream_rejects_malformed_tool_input(string $input): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('lookup');

        iterator_to_array($this->bedrock($input)->stream(new UserMessage('Where?')), false);
    }

    public function test_empty_arguments_still_mean_a_call_without_inputs(): void
    {
        $this->assertSame([], $this->mistral('')->chat(new UserMessage('Now?'))->message()->getToolCalls()[0]->getInputs());
        $this->assertSame([], $this->zai('')->chat(new UserMessage('Now?'))->message()->getToolCalls()[0]->getInputs());

        $generator = $this->bedrock('')->stream(new UserMessage('Now?'));
        iterator_to_array($generator, false);
        $this->assertSame([], $generator->getReturn()->message()->getToolCalls()[0]->getInputs());
    }
}
