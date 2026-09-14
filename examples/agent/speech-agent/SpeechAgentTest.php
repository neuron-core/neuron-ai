<?php

declare(strict_types=1);

namespace NeuronAI\Tests\SpeechExperiment;

require_once __DIR__ . '/bootstrap.php';

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\InferenceNode;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Agent\Memory\Stub\InspectableMemory;
use NeuronAI\Tests\Agent\Stub\GetWeatherTool;
use NeuronAI\Tests\SpeechExperiment\Nodes\TextToSpeechNode;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Exporter\WorkflowGraphBuilder;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function base64_decode;
use function base64_encode;

class SpeechAgentTest extends TestCase
{
    protected function audio(string $text): AudioContent
    {
        return new AudioContent(base64_encode($text), SourceType::BASE64, 'application/x-fake-speech');
    }

    public function test_chat_preserves_input_metadata_and_block_order_without_mutating_the_caller(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('It is sunny.'));
        $agent = DemoSpeechAgent::make();
        $agent->setAiProvider($provider);
        $input = new UserMessage([new TextContent('Please answer:'), $this->audio('Weather in Rome?')]);
        $input->addMetadata('source', 'microphone');
        $state = $agent->chat($input);

        $sent = $provider->getRecorded()[0]->messages[0];
        $this->assertSame('Please answer: Weather in Rome?', $sent->getContent());
        $this->assertSame($input->getMetadata('__id'), $sent->getMetadata('__id'));
        $this->assertSame('microphone', $sent->getMetadata('source'));
        $this->assertNull($sent->getAudio());
        $this->assertNotNull($input->getAudio());
        $this->assertSame('Please answer:', $input->getContent());
        $this->assertSame('It is sunny.', base64_decode($state->get('speech.audio')->content));
        $this->assertSame('It is sunny.', $state->getMessage()->getContent());
        $this->assertNull($state->getMessage()->getAudio());
        $this->assertCount(2, $agent->getChatHistory()->getMessages());
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
    }

    public function test_multiple_audio_blocks_are_transcribed_in_order(): void
    {
        $agent = DemoSpeechAgent::make();
        $state = $agent->chat(new UserMessage([$this->audio('Hello'), $this->audio('world')]));
        $this->assertSame(['Hello', 'world'], $agent->transcribed);
        $this->assertSame('Hello world', $state->request->messages[0]->getContent());
    }

    public function test_stream_forwards_text_and_produces_audio_only_at_completion(): void
    {
        $agent = DemoSpeechAgent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello there.')));
        $stream = $agent->stream(new UserMessage($this->audio('Hello')));
        $text = '';
        foreach ($stream as $chunk) {
            if ($chunk instanceof TextChunk) {
                $this->assertSame([], $agent->synthesized);
                $text .= $chunk->content;
            }
        }
        $this->assertSame('Hello there.', $text);
        $this->assertSame(['Hello there.'], $agent->synthesized);
        $this->assertInstanceOf(AudioContent::class, $stream->getReturn()->get('speech.audio'));
    }

    public function test_tool_approval_resume_keeps_speech_outside_the_tool_loop(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage('Checking weather.', [new ToolCall('get_weather', 'weather-1', ['location' => 'Rome'])]),
            new AssistantMessage('Rome is sunny.'),
        );
        $agent = DemoSpeechAgent::make();
        $agent->setAiProvider($provider)
            ->addTool(GetWeatherTool::make()->requireApproval());
        $paused = $agent->chat(new UserMessage($this->audio('Weather in Rome?')));
        $this->assertTrue($paused->isInterrupted());
        $this->assertNull($paused->get('speech.audio'));
        $this->assertSame([], $agent->synthesized);

        $state = $agent->submitApprovalDecisions(['weather-1' => 'approve'])->run();
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame(['Weather in Rome?'], $agent->transcribed);
        $this->assertSame(['Rome is sunny.'], $agent->synthesized);
        $this->assertSame(2, $provider->getCallCount());
        $this->assertCount(4, $agent->getChatHistory()->getMessages());
    }

    public function test_memory_runs_after_transcription_and_before_synthesis(): void
    {
        $memory = new InspectableMemory();
        $agent = DemoSpeechAgent::make(threadId: 'speech-memory');
        $agent->setMemory($memory);
        $agent->failSynthesis = true;
        try {
            $agent->chat(new UserMessage($this->audio('Remember my name.')));
            $this->fail('Expected the speech provider to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated speech provider failure.', $error->getMessage());
        }
        $this->assertSame(['Remember my name.'], $memory->recalls);
        $this->assertCount(1, $memory->remembered);
        $this->assertSame('Remember my name.', $memory->remembered[0][1]);
        $this->assertSame(WorkflowStatus::Failed, $agent->getState()->getStatus());

        $agent->failSynthesis = false;
        $state = $agent->run();
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertCount(1, $memory->remembered);
        $this->assertCount(1, $agent->transcribed);
        $this->assertCount(2, $agent->synthesized);
    }

    public function test_disabling_memory_still_reaches_speech(): void
    {
        $memory = new InspectableMemory();
        $agent = DemoSpeechAgent::make();
        $agent->setMemory($memory)->setMemoryUsage(false, false);
        $state = $agent->chat(new UserMessage($this->audio('Hello')));
        $this->assertSame([], $memory->recalls);
        $this->assertSame([], $memory->remembered);
        $this->assertInstanceOf(AudioContent::class, $state->get('speech.audio'));
    }

    public function test_fresh_instance_recovers_failed_synthesis_without_repeating_inference_or_transcription(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello.'));
        $first = DemoSpeechAgent::make(threadId: 'speech-recovery');
        $first->setAiProvider($provider);
        $first->failSynthesis = true;
        try {
            $first->chat(new UserMessage($this->audio('Hello')));
            $this->fail('Expected speech failure.');
        } catch (RuntimeException) {
        }
        $runId = $first->getRunId();
        $second = DemoSpeechAgent::make(workflowId: $first->getWorkflowId());
        $second->setAiProvider($provider);
        $second->setChatHistory($first->getChatHistory());
        $second->setPersistence($first->getPersistence());
        $state = $second->run();

        $this->assertSame($runId, $second->getRunId());
        $this->assertSame('speech-recovery', $second->getThreadId());
        $this->assertSame([], $second->transcribed);
        $this->assertSame(['Hello.'], $second->synthesized);
        $this->assertSame(1, $provider->getCallCount());
        $this->assertCount(2, $second->getChatHistory()->getMessages());
        $this->assertInstanceOf(AudioContent::class, $state->get('speech.audio'));
    }

    public function test_new_turn_does_not_expose_previous_audio_during_approval(): void
    {
        $agent = DemoSpeechAgent::make();
        $agent->setAiProvider(new FakeAIProvider(
            new AssistantMessage('First reply.'),
            new ToolCallMessage(null, [new ToolCall('get_weather', 'weather-2', ['location' => 'Rome'])]),
        ))->addTool(GetWeatherTool::make()->requireApproval());
        $agent->chat(new UserMessage($this->audio('Hello')));
        $state = $agent->chat(new UserMessage('Weather?'));
        $this->assertTrue($state->isInterrupted());
        $this->assertNull($state->get('speech.audio'));
        $this->assertSame(['Hello'], $agent->transcribed);
        $this->assertSame(['First reply.'], $agent->synthesized);
    }

    public function test_structured_returns_the_object_while_audio_remains_in_state(): void
    {
        $agent = DemoSpeechAgent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('{"name":"Ada"}')));
        $result = $agent->structured(new UserMessage($this->audio('My name is Ada.')), User::class);
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('Ada', $result->name);
        $this->assertSame(['{"name":"Ada"}'], $agent->synthesized);
        $this->assertInstanceOf(AudioContent::class, $agent->getState()->get('speech.audio'));
    }

    public function test_inference_middleware_runs_on_the_original_chat_node(): void
    {
        $middleware = new FakeMiddleware();
        $agent = DemoSpeechAgent::make();
        $agent->addMiddleware(InferenceNode::class, $middleware);
        $agent->chat(new UserMessage($this->audio('Hello')));
        $this->assertCount(1, $middleware->getBeforeRecords());
        $this->assertInstanceOf(ChatNode::class, $middleware->getBeforeRecords()[0]->node);
        $this->assertInstanceOf(AgentOutputEvent::class, $middleware->getAfterRecords()[0]->event);
    }

    public function test_appending_a_speech_node_conflicts_with_the_default_exit(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hello')));
        $agent->addNode(new TextToSpeechNode(new FakeAIProvider(new AssistantMessage($this->audio('Hello')))));
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('already exists');
        $agent->bootstrap();
    }

    public function test_add_node_cannot_replace_an_existing_event_handler(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider());
        $agent->addNode(new ChatNode(new FakeAIProvider(), $agent->getChatHistory()));
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('already exists');
        $agent->bootstrap();
    }

    public function test_exported_inference_edges_reach_the_speech_output(): void
    {
        $agent = DemoSpeechAgent::make();
        $agent->bootstrap();
        $graph = (new WorkflowGraphBuilder())->build($agent->getStartEvent()::class, $agent->getEventNodeMap());
        $targets = [];
        foreach ($graph->getEdges() as $edge) {
            if ($graph->getVertex($edge->from)->label === 'ChatNode') {
                $targets[] = $graph->getVertex($edge->to)->label;
            }
        }
        $this->assertContains('AgentOutputEvent', $targets);
        $this->assertNotContains('StopEvent', $targets);
        $this->assertInstanceOf(TextToSpeechNode::class, $agent->getEventNodeMap()[AgentOutputEvent::class]);
    }

    public function test_transcription_failure_does_not_call_the_llm_or_write_history(): void
    {
        $provider = new FakeAIProvider();
        $agent = DemoSpeechAgent::make();
        $agent->setAiProvider($provider);
        try {
            $agent->chat(new UserMessage(new AudioContent('invalid base64!', SourceType::BASE64)));
            $this->fail('Expected transcription failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('The fake transcriber expects base64-encoded text.', $error->getMessage());
        }
        $this->assertSame(0, $provider->getCallCount());
        $this->assertSame([], $agent->getChatHistory()->getMessages());
        $this->assertSame([], $agent->synthesized);
    }
}
