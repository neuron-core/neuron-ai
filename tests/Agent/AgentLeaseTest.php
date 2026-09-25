<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use Throwable;

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
        $this->assertSame(600, $this->admittedLease(Agent::make()));
        $this->assertNull($this->admittedLease(Workflow::make()));
    }

    public function test_null_disables_the_default_lease(): void
    {
        $this->assertNull($this->admittedLease(Agent::make()->setLeaseTimeout(null)));
    }

    public function test_the_hook_override_and_the_setter_win_over_the_default(): void
    {
        $agent = new class () extends Agent {
            protected function leaseTimeout(): int
            {
                return 5;
            }
        };

        $this->assertSame(5, $this->admittedLease($agent));
        $this->assertSame(30, $this->admittedLease($agent->setLeaseTimeout(30)));
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
        $agent->setMessageStore(new InMemoryMessageStore());
        $agent->setPersistence($persistence);

        $before = time();
        $agent->chat(new UserMessage('Hello'));

        $renewals = array_filter(
            $persistence->controls,
            fn (WorkflowControl $control): bool => $control->status === WorkflowStatus::Running
                && $control->leaseExpiresAt !== null
                && $control->leaseExpiresAt >= $before + 600,
        );

        // One renewal per node commit: StartNode, ChatNode, then EndNode.
        $this->assertCount(3, $renewals);
    }

    /**
     * The lease a run is admitted with: the deadline of its first control
     * record, in seconds from the moment it was written.
     */
    protected function admittedLease(Workflow $workflow): ?int
    {
        $persistence = new class () extends InMemoryPersistence {
            public ?int $lease = null;

            public function initializeIfAbsent(
                string $partition,
                string $conditionKey,
                string $initialValue,
                array $records = [],
            ): bool {
                $control = (new PhpSerializer())->unserialize($initialValue);
                $this->lease = $control->leaseExpiresAt === null ? null : $control->leaseExpiresAt - time();

                return parent::initializeIfAbsent($partition, $conditionKey, $initialValue, $records);
            }
        };

        try {
            $workflow->setPersistence($persistence)->run();
        } catch (Throwable) {
            // Neither a provider nor nodes are configured: only the admission matters.
        }

        return $persistence->lease;
    }
}
