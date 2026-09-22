<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Generator;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class AgentIdempotencyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function entryPoints(): iterable
    {
        yield 'chat' => ['chat'];
        yield 'stream' => ['stream'];
        yield 'structured' => ['structured'];
    }

    #[DataProvider('entryPoints')]
    public function test_reconstructed_agent_reuses_saved_inference(string $entryPoint): void
    {
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider(new AssistantMessage('{"name":"Ada"}'));
        $make = static function () use ($persistence, $provider): Agent {
            $agent = Agent::make(threadId: 'thread')->setPersistence($persistence)->retainCompletionUntilAcknowledged();
            $agent->setAiProvider($provider);
            return $agent;
        };
        $invoke = static function (Agent $agent) use ($entryPoint): mixed {
            $messages = new UserMessage('My name is Ada.');
            $result = $entryPoint === 'structured'
                ? $agent->structured($messages, User::class, idempotencyKey: 'request')
                : $agent->$entryPoint($messages, idempotencyKey: 'request');
            if ($result instanceof Generator) {
                iterator_to_array($result);
                return $result->getReturn();
            }
            return $result;
        };
        $firstAgent = $make();
        $first = $invoke($firstAgent);
        $secondAgent = $make();
        $second = $invoke($secondAgent);
        if ($entryPoint === 'structured') {
            self::assertEquals($first, $second);
        } else {
            self::assertEquals($first->getMessage(), $second->getMessage());
            self::assertSame($first->getStatus(), $second->getStatus());
        }
        self::assertSame($firstAgent->inspect()?->runId, $secondAgent->inspect()?->runId);
        $provider->assertCallCount(1);
    }

    public function test_changed_messages_with_the_same_key_are_rejected(): void
    {
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider(new AssistantMessage('Hello'));
        $make = static function () use ($persistence, $provider): Agent {
            $agent = Agent::make(threadId: 'thread')->setPersistence($persistence)->retainCompletionUntilAcknowledged();
            $agent->setAiProvider($provider);
            return $agent;
        };
        $make()->chat(new UserMessage('first'), idempotencyKey: 'key');
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('different workflow operation');
        $make()->chat(new UserMessage('changed'), idempotencyKey: 'key');
    }
}
