<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Conversation;

use LogicException;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Evaluation\Conversation\Conversation;
use NeuronAI\Evaluation\EvaluationException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Executor\SequentialBranchRunner;
use PHPUnit\Framework\TestCase;

class ConversationInvalidDecisionTest extends TestCase
{
    public function test_an_invalid_decision_value_is_rejected_instead_of_re_suspending_forever(): void
    {
        $agent = Agent::make();
        $agent->setMessageStore(new InMemoryMessageStore());
        $agent->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'PHP'])]),
            new AssistantMessage('Done.'),
        ));
        $agent->addTool((new SearchTool())->requireApproval());
        $agent->setBranchRunner(new SequentialBranchRunner());
        $policyCalls = 0;

        $conversation = Conversation::make($agent)
            ->withTurns(['Search for PHP'])
            ->withApprovals(function () use (&$policyCalls): array {
                // Without this guard the conversation loops forever
                if (++$policyCalls > 1) {
                    throw new LogicException('The agent re-suspended on the same request');
                }

                // A typo of 'approve': every call has a key, but the decision is not a valid one
                return ['call_1' => 'approved'];
            });

        $this->expectException(EvaluationException::class);

        $conversation->run();
    }
}
