<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Middleware\Stub\RequestEditingMiddleware;
use NeuronAI\Tests\Agent\Stub\ClosureDependencyTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class AgentRequestStateTest extends TestCase
{
    #[TestWith(['chat'])]
    #[TestWith(['stream'])]
    #[TestWith(['structured'])]
    public function test_middleware_changes_survive_resume_with_fresh_services(string $mode): void
    {
        $persistence = new InMemoryPersistence();
        $history = new InMemoryChatHistory('request-state');
        $tool = (new ClosureDependencyTool(static fn (): int => 42))->requireApproval();
        $middleware = new RequestEditingMiddleware($tool);
        $firstProvider = new FakeAIProvider(new ToolCallMessage(null, [ToolCall::make('count_users', 'call_1')]));
        $first = Agent::make(threadId: 'request-state');
        $first->setPersistence($persistence)
            ->setChatHistory($history)
            ->setAiProvider($firstProvider)
            ->setInstructions('Agent defaults')
            ->addTool(new SearchTool());
        $first->addGlobalMiddleware($middleware);

        $question = new UserMessage('Original question');
        if ($mode === 'stream') {
            iterator_to_array($first->stream($question));
        } elseif ($mode === 'structured') {
            $first->structured($question, User::class);
        } else {
            $first->chat($question);
        }

        $this->assertTrue($first->getState()->isInterrupted());
        $this->assertSame(1, $middleware->entryCalls);
        $firstProvider->assertSystemPrompt('Middleware instructions');
        $firstProvider->assertToolsConfigured(['count_users']);
        $this->assertSame('Middleware question', $history->getMessages()[0]->getContent());

        $freshTool = (new ClosureDependencyTool(static fn (): int => 42))->requireApproval();
        $freshMiddleware = new RequestEditingMiddleware($freshTool, 'Changed middleware defaults');
        $freshProvider = new FakeAIProvider(new AssistantMessage('{"name":"Recovered"}'));
        $resumed = Agent::make(threadId: 'request-state');
        $resumed->setPersistence($persistence)
            ->setChatHistory($history)
            ->setAiProvider($freshProvider)
            ->setInstructions('Changed agent defaults')
            ->addTool(new SearchTool());
        $resumed->addGlobalMiddleware($freshMiddleware);

        $resumed->toolApprovalDecisions(['call_1' => 'approve']);
        $state = $resumed->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame(0, $freshMiddleware->entryCalls, 'The recorded entry step and its middleware are skipped.');
        $this->assertSame([['search'], ['count_users']], $freshMiddleware->toolSelections);
        $freshProvider->assertMethodCallCount($mode, 1);
        $freshProvider->assertSystemPrompt('Middleware instructions');
        $freshProvider->assertToolsConfigured(['count_users']);
        $this->assertSame(0, $state->request->options->maxRetries);
        $this->assertFalse($state->request->options->rememberMemory);
        $toolResult = $state->request->messages[0];
        $this->assertInstanceOf(ToolResultMessage::class, $toolResult);
        $this->assertSame('42', $toolResult->getToolCalls()[0]->getResult());
        if ($mode === 'structured') {
            $this->assertInstanceOf(User::class, $state->get('structured_output'));
        }
    }
}
