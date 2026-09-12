<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Frontend;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Frontend\VercelAIInputTranslator;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InputTranslatorFlowTest extends TestCase
{
    protected InMemoryPersistence $persistence;
    protected InMemoryChatHistory $history;
    protected FakeAIProvider $provider;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->history = new InMemoryChatHistory('frontend');
        $this->provider = new FakeAIProvider();
    }

    protected function agent(): Agent
    {
        $agent = Agent::make();
        $agent->setPersistence($this->persistence)->setChatHistory($this->history)
            ->setAiProvider($this->provider)->addTool((new FrontendTool('browser'))->requireApproval());
        return $agent;
    }

    /** @return iterable<string, array{InputTranslatorInterface}> */
    public static function translators(): iterable
    {
        yield 'agui' => [new AGUIInputTranslator()];
        yield 'vercel' => [new VercelAIInputTranslator()];
    }

    #[DataProvider('translators')]
    public function test_approval_then_partial_tool_results_resume_fresh_agents(InputTranslatorInterface $translator): void
    {
        $this->provider->addResponses(
            new ToolCallMessage(null, [
                new ToolCall('browser', 'a', deferred: true),
                new ToolCall('browser', 'b', deferred: true),
                new ToolCall('browser', 'c', deferred: true),
            ]),
            new AssistantMessage('Finished'),
        );
        $state = $this->agent()->chat(new UserMessage('Read the page'));
        $this->assertInstanceOf(ApprovalRequest::class, $state->getInterruptRequest());
        $agent = $this->agent();
        $before = serialize($this->persistence);
        $agent->submitInputs($this->approvalPayload($translator), $translator);
        $this->assertSame($before, serialize($this->persistence));
        $this->assertNull($agent->getRunId());
        $events = $agent->events();
        iterator_to_array($events);
        $this->assertInstanceOf(ToolResultsRequest::class, $events->getReturn()->getInterruptRequest());
        $this->assertSame(1, $this->provider->getCallCount());

        foreach ([['a' => 'Page title'], ['a' => 'Page title', 'b' => 'Page URL']] as $results) {
            $agent = $this->agent();
            $events = $agent->submitInputs($this->resultPayload($translator, $results), $translator)->events();
            iterator_to_array($events);
        }
        $this->assertFalse($events->getReturn()->isInterrupted());
        $this->assertSame(2, $this->provider->getCallCount());
        $this->assertNull($this->persistence->get('frontend', '__control'));
        $messages = $this->history->getMessages();
        $this->assertCount(4, $messages);
        $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);
        $calls = $messages[2]->getToolCalls();
        $this->assertSame('Page title', $calls[0]->getResult());
        $this->assertSame('Page URL', $calls[1]->getResult());
        $this->assertSame(ApprovalState::Rejected, $calls[2]->getApprovalState());
        $this->assertStringContainsString('TOOL NOT EXECUTED', $calls[2]->getResult());
        $providerResults = array_values(array_filter(
            $this->provider->getRecorded()[1]->messages,
            fn (Message $message): bool => $message instanceof ToolResultMessage,
        ));
        $this->assertCount(1, $providerResults);
        $this->assertSame('Page title', $providerResults[0]->getToolCalls()[0]->getResult());
        $this->assertSame('Page URL', $providerResults[0]->getToolCalls()[1]->getResult());
    }

    /** @return array<string, mixed> */
    protected function approvalPayload(InputTranslatorInterface $translator): array
    {
        if ($translator instanceof AGUIInputTranslator) {
            return ['resume' => [
                ['interruptId' => 'a', 'status' => 'resolved', 'payload' => ['approved' => true]],
                ['interruptId' => 'b', 'status' => 'resolved', 'payload' => ['approved' => true]],
                ['interruptId' => 'c', 'status' => 'resolved', 'payload' => ['approved' => false]],
            ]];
        }
        $parts = [];
        foreach (['a' => true, 'b' => true, 'c' => false] as $id => $approved) {
            $parts[] = ['type' => 'tool-browser', 'toolCallId' => $id, 'state' => 'approval-responded',
                'approval' => ['id' => $id, 'approved' => $approved]];
        }
        return ['messages' => [['role' => 'assistant', 'parts' => $parts]]];
    }

    /**
     * @param array<string, string> $results
     * @return array<string, mixed>
     */
    protected function resultPayload(InputTranslatorInterface $translator, array $results): array
    {
        $entries = [];
        foreach ($results as $id => $result) {
            $entries[] = $translator instanceof AGUIInputTranslator
                ? ['role' => 'tool', 'toolCallId' => $id, 'content' => $result]
                : ['type' => 'tool-browser', 'toolCallId' => $id, 'state' => 'output-available', 'output' => $result];
        }
        return ['messages' => $translator instanceof AGUIInputTranslator ? $entries : [['role' => 'assistant', 'parts' => $entries]]];
    }
}
