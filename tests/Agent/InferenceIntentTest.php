<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentRunOptions;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\RecallMemoryEvent;
use NeuronAI\Agent\Events\StructuredInferenceEvent;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Agent\Nodes\StartNode;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Agent\Stub\ClosureDependencyTool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use stdClass;

use function serialize;
use function unserialize;
use function get_object_vars;

class InferenceIntentTest extends TestCase
{
    public function test_default_options_are_independent_between_runs_and_requests(): void
    {
        $first = new AgentStartEvent();
        $second = new AgentStartEvent();
        $request = new InferenceRequest('Instructions');

        $this->assertFalse($first->options->stream);
        $this->assertNull($first->options->outputClass);
        $this->assertSame(1, $first->options->maxRetries);
        $this->assertTrue($first->options->recallMemory);
        $this->assertTrue($first->options->rememberMemory);

        $first->options->stream = true;
        $this->assertFalse($second->options->stream);
        $this->assertFalse($request->options->stream);
    }

    public function test_start_node_initializes_a_fresh_request_on_reused_state(): void
    {
        $instructions = new SystemMessage('Base instructions');
        $node = new StartNode($instructions, []);
        $state = new AgentState();
        $start = new AgentStartEvent(
            [new UserMessage('Original question')],
            new AgentRunOptions(stream: true, recallMemory: false, rememberMemory: false),
        );
        $event = $node($start, $state);
        $request = $state->request;

        $this->assertInstanceOf(AIInferenceEvent::class, $event);
        $this->assertSame($start->options, $request->options);
        $request->messages = [new UserMessage('Tool result')];
        $request->instructions->addContent(new SystemContent('Additional context'));

        $this->assertSame('Original question', $start->messages[0]->getContent());
        $this->assertSame('Base instructions', $instructions->getContent());

        $node(new AgentStartEvent([new UserMessage('Next question')]), $state);
        $this->assertNotSame($request, $state->request);
        $this->assertFalse($state->request->options->stream);
        $this->assertSame('Base instructions', $state->request->instructions->getContent());
        $this->assertSame('Next question', $state->request->messages[0]->getContent());
    }

    public function test_routing_uses_mutable_options_without_carrying_the_request(): void
    {
        $state = new AgentState();
        $state->request = new InferenceRequest('Instructions');
        $chat = AIInferenceEvent::fromRequest($state->request);
        $this->assertSame(AIInferenceEvent::class, $chat::class);

        $state->request->options->outputClass = stdClass::class;
        $state->request->options->maxRetries = 0;
        $structured = AIInferenceEvent::fromRequest($state->request);
        $this->assertInstanceOf(StructuredInferenceEvent::class, $structured);
        $this->assertSame([], get_object_vars($chat));
        $this->assertSame([], get_object_vars($structured));
    }

    public function test_memory_routing_preserves_the_state_request_options(): void
    {
        $start = new AgentStartEvent(options: new AgentRunOptions(outputClass: stdClass::class));
        $state = new AgentState();
        $event = (new StartNode(new SystemMessage('Instructions'), [], true))($start, $state);

        $this->assertInstanceOf(RecallMemoryEvent::class, $event);
        $this->assertSame($start->options, $state->request->options);
        $this->assertInstanceOf(StructuredInferenceEvent::class, AIInferenceEvent::fromRequest($state->request));
    }

    public function test_state_serializes_request_data_and_restores_live_tools(): void
    {
        $tool = new ClosureDependencyTool(static fn (): int => 42);
        $state = new AgentState();
        $state->request = new InferenceRequest(
            'Recorded instructions',
            [$tool],
            [new UserMessage('Question')],
            new AgentRunOptions(outputClass: stdClass::class, maxRetries: 0, recallMemory: false, rememberMemory: false),
        );
        $state->addStep(new UserMessage('Transient step'));
        $state->incrementToolRun('count_users');
        $restored = unserialize(serialize($state));

        $this->assertInstanceOf(AgentState::class, $restored);
        $this->assertSame([], $restored->request->tools);
        $this->assertSame('Recorded instructions', $restored->request->instructions->getContent());
        $this->assertSame('Question', $restored->request->messages[0]->getContent());
        $this->assertEquals($state->request->options, $restored->request->options);
        $this->assertSame([], $restored->getSteps());
        $this->assertSame(0, $restored->getToolRuns('count_users'));

        $agent = Agent::make();
        $agent->addTool($tool);
        $agent->restoreState($restored);
        $this->assertSame([$tool], $restored->request->tools);
        $this->assertSame([$tool], $state->request->tools);
    }

    public function test_cloned_state_isolates_nested_request_data_and_keeps_live_tools(): void
    {
        $tool = new ClosureDependencyTool(static fn (): int => 42);
        $call = ToolCall::make('count_users', 'call_1', ['filter' => ['active' => true]])->setResult('Original result');
        $state = new AgentState();
        $state->request = new InferenceRequest('Original instructions', [$tool], [new ToolCallMessage(null, [$call])]);
        $branch = clone $state;

        $branch->request->instructions->getTextBlocks()[0]->content = 'Branch instructions';
        $branchMessage = $branch->request->messages[0];
        $this->assertInstanceOf(ToolCallMessage::class, $branchMessage);
        $branchMessage->getToolCalls()[0]->setResult('Branch result');
        $branch->request->options->stream = true;
        $this->assertSame([$tool], $branch->request->tools);
        $branch->request->tools = [];

        $this->assertSame('Original instructions', $state->request->instructions->getContent());
        $this->assertSame('Original result', $call->getResult());
        $this->assertFalse($state->request->options->stream);
        $this->assertSame([$tool], $state->request->tools);
    }

    public function test_state_can_be_cloned_and_serialized_before_entry(): void
    {
        $state = new AgentState(['application' => 'value']);
        $clone = clone $state;
        $restored = unserialize(serialize($state));

        $this->assertFalse(isset($clone->request));
        $this->assertFalse(isset($restored->request));
        $this->assertSame('value', $restored->get('application'));
    }
}
