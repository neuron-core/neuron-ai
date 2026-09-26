<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Exceptions\AgentException;
use PHPUnit\Framework\Attributes\DataProvider;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\Nodes\StructuredOutputNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\StructuredOutput\Stub\Person;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function end;

class StructuredOutputNodeTest extends TestCase
{
    /**
     * Sanity: the retry loop still drives real inference per attempt when no
     * executor (hence no memoizer) is wired — memoize() then runs inline.
     */
    public function test_retry_succeeds_across_attempts(): void
    {
        $chatHistory = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $provider = new FakeAIProvider(
            new AssistantMessage('I cannot produce JSON'), // attempt 0 -> invalid
            new AssistantMessage('{"name": "Alice"}'),     // attempt 1 -> valid
        );

        $node = new StructuredOutputNode();
        $state = new AgentState();

        $state->request = new InferenceRequest(instructions: 'Test');
        $event = new StructuredInferenceEvent();
        $state->request->options->outputClass = User::class;
        $state->request->options->maxRetries = 1;
        $state->request->messages = [new UserMessage('Generate a user')];

        $node->setWorkflowContext(new NodeContext());

        $return = $node($event, $state, AgentResourcesFactory::make([], $chatHistory, $provider));

        $this->assertInstanceOf(AgentOutputEvent::class, $return);
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
        $node = new StructuredOutputNode();
        $chatHistory = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $state = new AgentState();
        $request = new InferenceRequest('Test', messages: [new UserMessage('Generate a user')]);
        $request->options->outputClass = User::class;
        $request->options->maxRetries = $maxRetries;
        $state->request = $request;
        $event = new StructuredInferenceEvent();
        $node->setWorkflowContext(new NodeContext());

        $this->expectException(AgentException::class);
        try {
            $node($event, $state, AgentResourcesFactory::make([], $chatHistory, $provider));
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
        $chatHistory = new ChatHistory(new InMemoryMessageStore(), 'thread');
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

        $state->request = new InferenceRequest(instructions: 'Test');
        $event = new StructuredInferenceEvent();
        $state->request->options->outputClass = User::class;
        $state->request->options->maxRetries = 1;
        $state->request->messages = [new UserMessage('Generate a user')];

        $node1 = new StructuredOutputNode();
        $node1->setWorkflowContext(new NodeContext(null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));

        $firstReturn = $node1($event, $state, AgentResourcesFactory::make([], $chatHistory, $provider));

        $this->assertInstanceOf(AgentOutputEvent::class, $firstReturn);
        $provider->assertMethodCallCount('structured', 2);
        $this->assertInstanceOf(User::class, $state->get('structured_output'));

        // Recovery: fresh engine + fresh state, same persistence. The retry inputs
        // (prior bad response + correction text) are reconstructed deterministically
        // from the recalled memos, so both attempts are served from cache.
        $state2 = new AgentState();
        $state2->request = clone $state->request;
        $state2->setExecutionMetadata($runId, $runId, 1);

        $node2 = new StructuredOutputNode();
        $node2->setWorkflowContext(new NodeContext(null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));

        $secondReturn = $node2($event, $state2, AgentResourcesFactory::make([], $chatHistory, $provider));

        $this->assertInstanceOf(AgentOutputEvent::class, $secondReturn);
        // No additional inference: still the original two calls.
        $provider->assertMethodCallCount('structured', 2);

        $recovered = $state2->get('structured_output');
        $this->assertInstanceOf(User::class, $recovered);
        $this->assertSame('Alice', $recovered->name);
    }

    protected function structuredState(string $outputClass, int $maxRetries): AgentState
    {
        $state = new AgentState();
        $state->request = new InferenceRequest('Test', messages: [new UserMessage('Generate a person')]);
        $state->request->options->outputClass = $outputClass;
        $state->request->options->maxRetries = $maxRetries;

        return $state;
    }

    protected function validPerson(string $firstName): AssistantMessage
    {
        return new AssistantMessage(
            '{"firstName":"' . $firstName . '","lastName":"Doe","address":{"street":"Main St","city":"Rome","zip":"00100"},"tags":[]}'
        );
    }

    public function test_validation_violations_are_fed_back_to_the_model(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $provider = new FakeAIProvider($this->validPerson(''), $this->validPerson('Jane'));
        $state = $this->structuredState(Person::class, 1);
        $node = new StructuredOutputNode();
        $node->setWorkflowContext(new NodeContext());

        $this->assertInstanceOf(AgentOutputEvent::class, $node(new StructuredInferenceEvent(), $state, AgentResourcesFactory::make([], $history, $provider)));

        $correction = "There was a problem in your previous response that generated the following error:\n\n"
            . "\n- firstName cannot be blank\n\n"
            . 'Try to generate the correct JSON structure based on the provided schema.';
        $retry = $provider->getRecorded()[1];
        $this->assertSame(['Generate a person', $this->validPerson('')->getContent(), $correction], array_map(
            static fn (Message $message): ?string => $message->getContent(),
            $retry->messages
        ));
        $this->assertSame(Person::class, $retry->structuredClass);
        $this->assertSame($provider->getRecorded()[0]->structuredSchema, $retry->structuredSchema);

        $output = $state->get('structured_output');
        $this->assertInstanceOf(Person::class, $output);
        $this->assertSame('Jane', $output->firstName);
        $this->assertSame(
            ['Generate a person', $this->validPerson('')->getContent(), $correction, $this->validPerson('Jane')->getContent()],
            array_map(static fn (Message $message): ?string => $message->getContent(), $history->getMessages())
        );
    }

    public function test_exhausted_retries_raise_the_last_error(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('not json'),
            $this->validPerson(''),
            new AssistantMessage('{"never": "requested"}'),
        );
        $state = $this->structuredState(Person::class, 1);
        $node = new StructuredOutputNode();
        $node->setWorkflowContext(new NodeContext());

        try {
            $node(new StructuredInferenceEvent(), $state, AgentResourcesFactory::make([], null, $provider));
            $this->fail('Exhausted retries must fail the node.');
        } catch (AgentException $exception) {
            $this->assertSame("\n- firstName cannot be blank", $exception->getMessage());
        }

        $provider->assertMethodCallCount('structured', 2);
        $this->assertFalse($state->has('structured_output'));
        $this->assertNull($state->getResponse());
    }

    public function test_a_response_without_json_is_retried_with_the_extraction_error(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Sorry, I cannot help.'), new AssistantMessage('{"name": "Alice"}'));
        $state = new AgentState();
        $state->request = new InferenceRequest('Test', [new UserMessage('Generate a user')]);
        $state->request->options->outputClass = User::class;
        $node = new StructuredOutputNode();
        $node->setWorkflowContext(new NodeContext());

        $node(new StructuredInferenceEvent(), $state, AgentResourcesFactory::make([], null, $provider));

        $messages = $provider->getRecorded()[1]->messages;
        $this->assertSame(
            "There was a problem in your previous response that generated the following error:\n\n"
            . "The response does not contains a valid JSON Object.\n\n"
            . 'Try to generate the correct JSON structure based on the provided schema.',
            end($messages)->getContent()
        );
        $output = $state->get('structured_output');
        $this->assertInstanceOf(User::class, $output);
        $this->assertSame('Alice', $output->name);
    }

    public function test_structured_inference_requires_an_output_class(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name": "Alice"}'));
        $state = new AgentState();
        $state->request = new InferenceRequest('Test', [new UserMessage('Generate a user')]);
        $node = new StructuredOutputNode();
        $node->setWorkflowContext(new NodeContext());

        try {
            $node(new StructuredInferenceEvent(), $state, AgentResourcesFactory::make([], null, $provider));
            $this->fail('A structured request without an output class must be refused.');
        } catch (AgentException $exception) {
            $this->assertSame('Structured inference requires an output class on the request.', $exception->getMessage());
        }

        $provider->assertNothingSent();
    }

    public function test_a_tool_call_routes_to_tools_and_commits_only_the_inbound(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $toolCall = new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'users'])]);
        $provider = new FakeAIProvider($toolCall);
        $state = $this->structuredState(User::class, 1);
        $node = new StructuredOutputNode();
        $node->setWorkflowContext(new NodeContext());

        $event = $node(new StructuredInferenceEvent(), $state, AgentResourcesFactory::make([], $history, $provider));

        $this->assertInstanceOf(ToolCallEvent::class, $event);
        $this->assertSame($toolCall, $event->toolCallMessage);
        // The tool node owns writing the tool call message.
        $this->assertSame(['Generate a person'], array_map(
            static fn (Message $message): ?string => $message->getContent(),
            $history->getMessages()
        ));
        $this->assertFalse($state->has('structured_output'));
    }

    public function test_a_schema_already_recorded_for_the_run_is_reused(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name": "Alice"}'));
        $state = $this->structuredState(User::class, 0);
        $state->set('structured_schema', ['type' => 'object', 'recorded' => true]);
        $node = new StructuredOutputNode();
        $node->setWorkflowContext(new NodeContext());

        $node(new StructuredInferenceEvent(), $state, AgentResourcesFactory::make([], null, $provider));

        $this->assertSame(['type' => 'object', 'recorded' => true], $provider->getRecorded()[0]->structuredSchema);
    }

    public function test_the_working_prompt_and_the_segment_tools_reach_the_provider(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name": "Alice"}'));
        $state = $this->structuredState(User::class, 0);
        $state->request->instructions = new SystemMessage('Working prompt');
        $node = new StructuredOutputNode();
        $node->setWorkflowContext(new NodeContext());

        $node(new StructuredInferenceEvent(), $state, AgentResourcesFactory::make([new SearchTool()], null, $provider, 'Segment base'));

        $record = $provider->getRecorded()[0];
        $this->assertSame('Working prompt', $record->systemPrompt?->getContent());
        $this->assertSame(['search'], array_map(static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(), $record->tools));
    }
}
