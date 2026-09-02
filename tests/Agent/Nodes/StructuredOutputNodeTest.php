<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Workflow\NodeContext;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PHPUnit\Framework\TestCase;

class StructuredOutputNodeTest extends TestCase
{
    /**
     * Sanity: the retry loop still drives real inference per attempt when no
     * executor (hence no memoizer) is wired — memoize() then runs inline.
     */
    public function test_retry_succeeds_across_attempts(): void
    {
        $chatHistory = new InMemoryChatHistory();
        $provider = new FakeAIProvider(
            new AssistantMessage('I cannot produce JSON'), // attempt 0 -> invalid
            new AssistantMessage('{"name": "Alice"}'),     // attempt 1 -> valid
        );

        $node = new StructuredOutputNode($provider, $chatHistory);
        $state = new AgentState();

        $event = new StructuredInferenceEvent(instructions: 'Test', tools: []);
        $event->setStructuredOutput(User::class, 1);
        $event->setMessages(new UserMessage('Generate a user'));

        $node->setWorkflowContext(new NodeContext($state, $event));

        $return = $node($event, $state);

        $this->assertInstanceOf(StopEvent::class, $return);
        $provider->assertMethodCallCount('structured', 2);

        $output = $state->get('structured_output');
        $this->assertInstanceOf(User::class, $output);
        $this->assertSame('Alice', $output->name);
    }

    /**
     * maxTries < 1 is normalized to 1, so the node always retries at least once
     * (2 attempts total) rather than aborting after the initial failed attempt.
     */
    public function test_max_tries_floored_to_one(): void
    {
        $chatHistory = new InMemoryChatHistory();
        $provider = new FakeAIProvider(
            new AssistantMessage('I cannot produce JSON'), // attempt 0 -> invalid
            new AssistantMessage('{"name": "Alice"}'),     // attempt 1 -> valid
        );

        $node = new StructuredOutputNode($provider, $chatHistory);
        $state = new AgentState();

        $event = new StructuredInferenceEvent(instructions: 'Test', tools: []);
        // Raw field writes bypass setStructuredOutput()'s own normalization,
        // so this exercises the node's floor specifically.
        $event->outputClass = User::class;
        $event->maxTries = 0;
        $event->setMessages(new UserMessage('Generate a user'));

        $node->setWorkflowContext(new NodeContext($state, $event));

        $return = $node($event, $state);

        $this->assertInstanceOf(StopEvent::class, $return);
        $provider->assertMethodCallCount('structured', 2);
        $this->assertInstanceOf(User::class, $state->get('structured_output'));
    }

    /**
     * After a crash between a succeeded inference's memo commit and the node-step
     * commit, re-running the node on a fresh engine (same persistence) must recall
     * every already-succeeded attempt instead of re-calling the provider. The
     * queue holds only two responses, so any re-call would throw.
     */
    public function test_recovery_recalls_inference_without_re_calling(): void
    {
        $chatHistory = new InMemoryChatHistory();
        $provider = new FakeAIProvider(
            new AssistantMessage('I cannot produce JSON'), // attempt 0 -> invalid
            new AssistantMessage('{"name": "Alice"}'),     // attempt 1 -> valid
        );

        $runId = 'structured_recovery_test';
        $persistence = new InMemoryPersistence();
        $stepId = StructuredOutputNode::class . '-0';

        // Run 1: two real inference calls (bad then good), node succeeds.
        $state = new AgentState();
        $state->setExecutionMetadata($runId, $runId, 1);

        $event = new StructuredInferenceEvent(instructions: 'Test', tools: []);
        $event->setStructuredOutput(User::class, 1);
        $event->setMessages(new UserMessage('Generate a user'));

        $node1 = new StructuredOutputNode($provider, $chatHistory);
        $node1->setWorkflowContext(new NodeContext($state, $event, null, false, new StepMemoizer($persistence, new PhpSerializer(), $runId, $stepId)));

        $firstReturn = $node1($event, $state);

        $this->assertInstanceOf(StopEvent::class, $firstReturn);
        $provider->assertMethodCallCount('structured', 2);
        $this->assertInstanceOf(User::class, $state->get('structured_output'));

        // Recovery: fresh engine + fresh state, same persistence. The retry inputs
        // (prior bad response + correction text) are reconstructed deterministically
        // from the recalled memos, so both attempts are served from cache.
        $state2 = new AgentState();
        $state2->setExecutionMetadata($runId, $runId, 1);

        $node2 = new StructuredOutputNode($provider, $chatHistory);
        $node2->setWorkflowContext(new NodeContext($state2, $event, null, false, new StepMemoizer($persistence, new PhpSerializer(), $runId, $stepId)));

        $secondReturn = $node2($event, $state2);

        $this->assertInstanceOf(StopEvent::class, $secondReturn);
        // No additional inference: still the original two calls.
        $provider->assertMethodCallCount('structured', 2);

        $recovered = $state2->get('structured_output');
        $this->assertInstanceOf(User::class, $recovered);
        $this->assertSame('Alice', $recovered->name);
    }
}
