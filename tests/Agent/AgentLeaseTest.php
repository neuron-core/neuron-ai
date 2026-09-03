<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function time;

/**
 * An Agent holds a lease by default: a process killed with no chance to
 * record its failure leaves the thread refused for at most the lease, after
 * which the next turn supersedes it. Plain workflows stay opt-in, and null
 * still disables the lease even though the Agent hook is non-null.
 */
class AgentLeaseTest extends TestCase
{
    public function test_agent_holds_a_ten_minute_lease_by_default(): void
    {
        $this->assertSame(600, Agent::make()->getLeaseTimeout());
        $this->assertNull(Workflow::make()->getLeaseTimeout());
    }

    public function test_null_disables_the_default_lease(): void
    {
        $this->assertNull(Agent::make()->setLeaseTimeout(null)->getLeaseTimeout());
    }

    public function test_the_hook_override_and_the_setter_win_over_the_default(): void
    {
        $agent = new class () extends Agent {
            protected function leaseTimeout(): int
            {
                return 5;
            }
        };

        $this->assertSame(5, $agent->getLeaseTimeout());
        $this->assertSame(30, $agent->setLeaseTimeout(30)->getLeaseTimeout());
    }

    public function test_a_turn_renews_the_lease_with_every_step_commit(): void
    {
        $serializer = new PhpSerializer();
        $persistence = new class ($serializer) extends InMemoryPersistence {
            /** @var WorkflowControl[] */
            public array $controls = [];

            public function __construct(protected PhpSerializer $serializer)
            {
            }

            public function writeIfUnchanged(
                string $partition,
                string $conditionKey,
                string $expectedValue,
                array $records,
            ): bool {
                $committed = parent::writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);

                if ($committed && isset($records['__control'])) {
                    $control = $this->serializer->unserialize($records['__control']);
                    if ($control instanceof WorkflowControl) {
                        $this->controls[] = $control;
                    }
                }

                return $committed;
            }
        };

        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('Hi!')));
        $agent->setChatHistory(new InMemoryChatHistory());
        $agent->setPersistence($persistence);

        $before = time();
        $agent->chat(new UserMessage('Hello'));

        $renewals = array_filter(
            $persistence->controls,
            fn (WorkflowControl $control): bool => $control->status === WorkflowStatus::Running
                && $control->leaseExpiresAt !== null
                && $control->leaseExpiresAt >= $before + 600,
        );

        // One renewal per node commit: StartNode, then ChatNode.
        $this->assertCount(2, $renewals);
    }
}
