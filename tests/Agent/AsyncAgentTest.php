<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Agent\Nodes\AmpChatNode;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Workflow\Async\AmpWorkflowExecutor;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use PHPUnit\Framework\TestCase;

use function Amp\Future\await;
use function microtime;

class AsyncAgent extends Agent
{
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): AgentHandler
    {
        $this->compose(new AmpChatNode($this->resolveProvider(), $messages));
        return new AgentHandler($this->init($interrupt));
    }
}

class AsyncAgentTest extends TestCase
{
    public function testAsyncAgentExecution(): void
    {
        $handler = AsyncAgent::make()
            ->setAiProvider(
                new Anthropic(
                    '',
                    'claude-haiku-4-5-20251001'
                )
            )
            ->chat(new UserMessage('What is 2+2?'));

        // Execute with Amp async executor
        $executor = new AmpWorkflowExecutor();
        $future = $executor->execute($handler);

        /** @var \NeuronAI\Agent\AgentState $result */
        $result = $future->await();

        $this->assertNotEmpty($result->getChatHistory()->getLastMessage()->getContent());
    }

    public function testConcurrentAgentExecution(): void
    {
        $handler1 = AsyncAgent::make()
            ->setAiProvider(
                new Anthropic(
                    '',
                    'claude-haiku-4-5-20251001'
                )
            )->chat(new UserMessage('What is 2+2?'));

        $handler2 = AsyncAgent::make()
            ->setAiProvider(
                new Anthropic(
                    '',
                    'claude-haiku-4-5-20251001'
                )
            )->chat(new UserMessage('What is 3+3?'));

        $handler3 = AsyncAgent::make()
            ->setAiProvider(
                new Anthropic(
                    '',
                    'claude-haiku-4-5-20251001'
                )
            )->chat(new UserMessage('What is 4+4?'));

        $executor = new AmpWorkflowExecutor();

        $startTime = microtime(true);

        // Execute three agents concurrently
        $future1 = $executor->execute($handler1);
        $future2 = $executor->execute($handler2);
        $future3 = $executor->execute($handler3);

        /**
         * @var \NeuronAI\Agent\AgentState[] $results
         */
        $results = await([$future1, $future2, $future3]);

        $duration = microtime(true) - $startTime;

        // Verify all agents completed
        $this->assertNotEmpty($results[0]->getChatHistory()->getLastMessage()->getContent());
        $this->assertNotEmpty($results[1]->getChatHistory()->getLastMessage()->getContent());
        $this->assertNotEmpty($results[2]->getChatHistory()->getLastMessage()->getContent());

        // Concurrent execution should be faster than 3x sequential
        // (This is a rough check - actual timing depends on API response times)
        echo "\nConcurrent execution took: {$duration}s\n";
    }
}
