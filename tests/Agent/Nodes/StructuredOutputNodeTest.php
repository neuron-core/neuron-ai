<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Exceptions\AgentException;
use PHPUnit\Framework\Attributes\DataProvider;
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
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
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

        $state->request = new InferenceRequest(instructions: 'Test', tools: []);
        $event = new StructuredInferenceEvent();
        $state->request->options->outputClass = User::class;
        $state->request->options->maxRetries = 1;
        $state->request->messages = [new UserMessage('Generate a user')];

        $node->setWorkflowContext(new NodeContext($state, $event));

        $return = $node($event, $state);

        $this->assertInstanceOf(StopEvent::class, $return);
        $provider->assertMethodCallCount('structured', 2);

        $output = $state->get('structured_output');
        $this->assertInstanceOf(User::class, $output);
        $this->assertSame('Alice', $output->name);
    }

    /** @return iterable<string, array{int}> */
    public static function no_retries(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-2];
    }

    #[DataProvider('no_retries')]
    public function test_no_retries_stops_after_the_initial_failure(int $maxRetries): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('I cannot produce JSON'),
            new AssistantMessage('{"name": "Alice"}'),
        );
        $node = new StructuredOutputNode($provider, new InMemoryChatHistory());
        $state = new AgentState();
        $request = new InferenceRequest('Test', messages: [new UserMessage('Generate a user')]);
        $request->options->outputClass = User::class;
        $request->options->maxRetries = $maxRetries;
        $state->request = $request;
        $event = new StructuredInferenceEvent();
        $node->setWorkflowContext(new NodeContext($state, $event));

        $this->expectException(AgentException::class);
        try {
            $node($event, $state);
        } finally {
            $provider->assertMethodCallCount('structured', 1);
        }
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

        $state->request = new InferenceRequest(instructions: 'Test', tools: []);
        $event = new StructuredInferenceEvent();
        $state->request->options->outputClass = User::class;
        $state->request->options->maxRetries = 1;
        $state->request->messages = [new UserMessage('Generate a user')];

        $node1 = new StructuredOutputNode($provider, $chatHistory);
        $node1->setWorkflowContext(new NodeContext($state, $event, null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));

        $firstReturn = $node1($event, $state);

        $this->assertInstanceOf(StopEvent::class, $firstReturn);
        $provider->assertMethodCallCount('structured', 2);
        $this->assertInstanceOf(User::class, $state->get('structured_output'));

        // Recovery: fresh engine + fresh state, same persistence. The retry inputs
        // (prior bad response + correction text) are reconstructed deterministically
        // from the recalled memos, so both attempts are served from cache.
        $state2 = new AgentState();
        $state2->request = clone $state->request;
        $state2->setExecutionMetadata($runId, $runId, 1);

        $node2 = new StructuredOutputNode($provider, $chatHistory);
        $node2->setWorkflowContext(new NodeContext($state2, $event, null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));

        $secondReturn = $node2($event, $state2);

        $this->assertInstanceOf(StopEvent::class, $secondReturn);
        // No additional inference: still the original two calls.
        $provider->assertMethodCallCount('structured', 2);

        $recovered = $state2->get('structured_output');
        $this->assertInstanceOf(User::class, $recovered);
        $this->assertSame('Alice', $recovered->name);
    }
}
