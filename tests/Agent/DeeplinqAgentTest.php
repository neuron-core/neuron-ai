<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use Deeplinq\Client;
use Deeplinq\Context;
use Deeplinq\Event as DeeplinqEvent;
use Deeplinq\Step;
use Deeplinq\StepPendingException;
use Deeplinq\Testing\FakeTransport;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Executor\DeeplinqStepEngine;
use NeuronAI\Workflow\Executor\DeeplinqTaskHandler;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use PHPUnit\Framework\TestCase;

class DeeplinqAgentTest extends TestCase
{
    /**
     * Simulate the Deeplinq platform replay loop for an agent.
     *
     * Each iteration: create a fresh Step with memoized data, invoke the handler,
     * catch StepPendingException, collect ops for the next replay.
     */
    private function replayUntilComplete(callable $agentFactory): AgentState
    {
        $memoized = [];

        for ($i = 0; $i < 20; $i++) {
            $step = new Step($memoized);
            $context = new Context(
                event: new DeeplinqEvent(name: 'test/trigger'),
                step: $step,
                runId: 'test-run-' . $i,
                attempt: 0,
            );

            $agent = $agentFactory();
            $handler = new DeeplinqTaskHandler(
                $agent,
                boot: fn (Agent $a) => $a->chat(new UserMessage('Hello'))->events(),
            );

            try {
                $gen = $handler($context);
                foreach ($gen as $_) {
                }
                return $gen->getReturn();
            } catch (StepPendingException) {
                foreach ($step->getOps() as $op) {
                    if (isset($op['data'])) {
                        $memoized[$op['id']] = ['data' => $op['data']];
                    }
                }
            }
        }

        $this->fail('Agent did not complete within 20 replays');
    }

    public function testSimpleAgentChatCompletesViaReplay(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('Hello from AI!'),
        );

        $state = $this->replayUntilComplete(function () use ($provider): Agent {
            $agent = Agent::make();
            $agent->setAiProvider($provider);
            return $agent;
        });

        $this->assertSame('Hello from AI!', $state->getMessage()->getContent());
    }

    public function testSendResumeUsesFakeTransport(): void
    {
        $transport = new FakeTransport();
        $transport->willReturn(['status' => 'accepted']);

        $client = new Client(
            appName: 'test-app',
            serveUrl: 'https://example.com/api/deeplinq',
            eventKey: 'test-key',
            transport: $transport,
        );

        $request = new ApprovalRequest('test approval');

        DeeplinqStepEngine::sendResume($client, 'workflow_123', $request);

        $this->assertCount(1, $transport->sent());

        $payload = $transport->sentPayloads()[0];
        $this->assertSame('workflow/interrupt/workflow_123', $payload['name']);

        // The data.resume field is a base64-encoded serialized InterruptRequest
        $resumed = unserialize(base64_decode($payload['data']['resume']));
        $this->assertInstanceOf(ApprovalRequest::class, $resumed);
    }
}
