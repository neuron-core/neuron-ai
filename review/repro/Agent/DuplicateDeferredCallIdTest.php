<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

class DuplicateDeferredCallIdTest extends TestCase
{
    public function test_one_result_never_answers_two_deferred_calls_sharing_an_id(): void
    {
        $persistence = new InMemoryPersistence();
        $store = new InMemoryMessageStore();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                new ToolCall('browser', 'browser', ['url' => 'https://a.example'], deferred: true),
                new ToolCall('browser', 'browser', ['url' => 'https://b.example'], deferred: true),
            ]),
            new AssistantMessage('Done'),
        );
        $agent = fn (): Agent => Agent::make(workflowId: 'duplicate-deferred')
            ->setPersistence($persistence)
            ->setMessageStore($store)
            ->setAiProvider($provider)
            ->addTool(new FrontendTool('browser'));

        $state = $agent()->chat(new UserMessage('Open both pages'));
        $request = $state->getInterruptRequest();
        $this->assertInstanceOf(ToolResultsRequest::class, $request);
        // Answer exactly what was advertised: each call gets the content of its own page.
        $answers = [];
        foreach ($request->getToolCalls() as $call) {
            $answers[$call->getCallId()] = ['result' => 'content of ' . $call->getInputs()['url']];
        }
        $agent()->submitToolResults($answers)->run();

        $results = [];
        foreach ($agent()->getChatHistory()->getMessages() as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->getToolCalls() as $call) {
                    $results[$call->getInputs()['url']] = (string) $call->getResult();
                }
            }
        }

        $this->assertCount(2, $results);
        $this->assertNotSame('content of https://b.example', $results['https://a.example']);
        $this->assertNotSame('content of https://a.example', $results['https://b.example']);
    }
}
