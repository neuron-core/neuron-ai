<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Frontend;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function serialize;

class AGUIEmptyResumeReproTest extends TestCase
{
    public function test_a_resolved_deferred_batch_without_results_is_rejected(): void
    {
        $request = (new ToolResultsRequest([new ToolCall('browser', 'a', deferred: true)]))->withId(4);

        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessage('The payload contains no matching continuation input.');

        (new AGUIInputTranslator())->translate(['resume' => [
            ['interruptId' => '4', 'status' => 'resolved', 'payload' => []],
        ]], $request);
    }

    public function test_an_empty_resume_of_a_suspended_agent_fails_before_execution(): void
    {
        $persistence = new InMemoryPersistence();
        $provider = new FakeAIProvider();
        $provider->addResponses(
            new ToolCallMessage(null, [new ToolCall('browser', 'a', deferred: true)]),
            new AssistantMessage('Finished'),
        );
        $agent = fn (): Agent => Agent::make()->setPersistence($persistence)->setMessageStore(new InMemoryMessageStore())
            ->setThreadId('frontend')->setAiProvider($provider)->addTool(new FrontendTool('browser'));
        $interrupt = $agent()->chat(new UserMessage('Read the page'))->getInterruptRequest();
        $this->assertInstanceOf(ToolResultsRequest::class, $interrupt);
        $before = serialize($persistence);

        try {
            $agent()->submitInputs(['resume' => [
                ['interruptId' => (string) $interrupt->getId(), 'status' => 'resolved', 'payload' => []],
            ]], new AGUIInputTranslator());
            $this->fail('An empty result map must not be accepted as a continuation.');
        } catch (InputTranslationException $exception) {
            $this->assertSame('The payload contains no matching continuation input.', $exception->getMessage());
        }
        $this->assertSame($before, serialize($persistence));
    }
}
