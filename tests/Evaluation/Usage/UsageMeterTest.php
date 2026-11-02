<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Usage;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Observability\InferenceStop;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\JudgeScoreOutput;
use NeuronAI\Evaluation\Usage\ModelUsage;
use NeuronAI\Evaluation\Usage\UsageMeter;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Executor\SequentialBranchRunner;
use NeuronAI\Workflow\Observability\WorkflowError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_shift;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class UsageMeterTest extends TestCase
{
    public function test_it_counts_each_call_of_a_watched_agent_and_its_copies_and_names_its_provider_and_model(): void
    {
        $meter = new UsageMeter();
        $agent = UsageMeter::watch($this->agent(
            (new AssistantMessage('One'))->setUsage(new Usage(100, 10, 40, reasoningTokens: 5)),
            (new AssistantMessage('Two'))->setUsage(new Usage(200, 20)),
        ));

        $meter->during(fn (): mixed => $agent->for('thread')->chat(new UserMessage('Hi')));
        $meter->during(fn (): mixed => $agent->for('thread')->chat(new UserMessage('Again')));

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 2, 300, 30, 40, 0, 5)], $meter->usage());
    }

    public function test_each_provider_and_model_has_its_own_record(): void
    {
        $meter = new UsageMeter();
        $cheap = UsageMeter::watch($this->agent((new AssistantMessage('One'))->setUsage(new Usage(100, 10))));
        $provider = $this->largeModel((new AssistantMessage('Two'))->setUsage(new Usage(200, 20)));
        $large = UsageMeter::watch(Agent::make()->setAiProvider($provider));

        $meter->during(function () use ($cheap, $large): void {
            $cheap->for('a')->chat(new UserMessage('Hi'));
            $large->for('b')->chat(new UserMessage('Hi'));
        });

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10), new ModelUsage($provider::class, 'large', 1, 200, 20)], $meter->usage());
    }

    public function test_the_step_running_at_that_moment_gets_the_tokens(): void
    {
        $run = new UsageMeter();
        $judge = new UsageMeter();
        $agent = UsageMeter::watch($this->agent(
            (new AssistantMessage('One'))->setUsage(new Usage(100, 10)),
            (new AssistantMessage('Two'))->setUsage(new Usage(200, 20)),
        ));

        $run->during(fn (): mixed => $agent->for('a')->chat(new UserMessage('Hi')));
        $judge->during(fn (): mixed => $agent->for('b')->chat(new UserMessage('Hi')));

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $run->usage());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 200, 20)], $judge->usage());
    }

    public function test_what_a_step_consumed_before_throwing_is_kept_and_the_next_call_counts_nowhere(): void
    {
        $meter = new UsageMeter();
        $agent = UsageMeter::watch($this->agent(
            (new AssistantMessage('One'))->setUsage(new Usage(100, 10)),
            (new AssistantMessage('Two'))->setUsage(new Usage(200, 20)),
        ));

        try {
            $meter->during(function () use ($agent): void {
                $agent->for('a')->chat(new UserMessage('Hi'));
                throw new RuntimeException('after the call');
            });
        } catch (RuntimeException) {
        }

        $agent->for('b')->chat(new UserMessage('Hi'));

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $meter->usage());
    }

    public function test_the_tokens_written_to_the_provider_cache_are_read_from_the_message(): void
    {
        $meter = new UsageMeter();
        $message = (new AssistantMessage('One'))->setUsage(new Usage(100, 10));
        $message->addMetadata('cacheWriteTokens', 30);
        $agent = UsageMeter::watch($this->agent($message));

        $meter->during(fn (): mixed => $agent->for('thread')->chat(new UserMessage('Hi')));

        $this->assertSame(30, $meter->usage()[0]->cacheWriteTokens);
    }

    public function test_what_a_provider_tool_read_counts_as_input_tokens(): void
    {
        $meter = new UsageMeter();
        $message = (new AssistantMessage('One'))->setUsage(new Usage(27, 45));
        $message->addMetadata('toolInputTokens', 10309);
        $agent = UsageMeter::watch($this->agent($message));

        $meter->during(fn (): mixed => $agent->for('thread')->chat(new UserMessage('Hi')));

        $this->assertSame(27 + 10309, $meter->usage()[0]->inputTokens);
        $this->assertSame(27, $message->getUsage()->inputTokens);
    }

    public function test_a_retry_of_structured_output_is_a_call_of_its_own(): void
    {
        $meter = new UsageMeter();
        $valid = json_encode(['score' => 0.5, 'reasoning' => 'Fine.'], JSON_THROW_ON_ERROR);
        $agent = UsageMeter::watch($this->agent(
            (new AssistantMessage('not json'))->setUsage(new Usage(100, 10)),
            (new AssistantMessage($valid))->setUsage(new Usage(100, 10)),
        ));

        $meter->during(fn (): mixed => $agent->for('thread')->structured(new UserMessage('Hi'), JudgeScoreOutput::class, maxRetries: 2));

        $this->assertSame(2, $meter->usage()[0]->calls);
    }

    public function test_watching_an_agent_on_every_item_counts_its_calls_once(): void
    {
        $meter = new UsageMeter();
        $agent = $this->agent((new AssistantMessage('One'))->setUsage(new Usage(100, 10)));

        UsageMeter::watch($agent);
        UsageMeter::watch($agent);
        UsageMeter::watch($agent);
        $meter->during(fn (): mixed => $agent->for('thread')->chat(new UserMessage('Hi')));

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $meter->usage());
    }

    public function test_an_event_is_counted_once_even_when_another_reaches_the_meter_between_its_two_deliveries(): void
    {
        $meter = new UsageMeter();
        $critic = UsageMeter::watch($this->agent((new AssistantMessage('Fine'))->setUsage(new Usage(5, 5))));
        $agent = UsageMeter::watch($this->agent((new AssistantMessage('One'))->setUsage(new Usage(100, 10))));
        $agent->subscribe(InferenceStop::class, static function () use ($critic): void {
            $critic->for('critic')->chat(new UserMessage('Check it'));
        });
        UsageMeter::watch($agent);

        $meter->during(fn (): mixed => $agent->for('thread')->chat(new UserMessage('Hi')));

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 2, 105, 15)], $meter->usage());
    }

    public function test_a_copy_with_a_provider_of_its_own_is_booked_under_that_provider_and_its_model(): void
    {
        $meter = new UsageMeter();
        $agent = UsageMeter::watch($this->agent((new AssistantMessage('One'))->setUsage(new Usage(100, 10))));
        $large = $this->largeModel((new AssistantMessage('Two'))->setUsage(new Usage(200, 20)));

        $meter->during(fn (): mixed => $agent->for('thread')->setAiProvider($large)->chat(new UserMessage('Hi')));

        $this->assertEquals([new ModelUsage($large::class, 'large', 1, 200, 20)], $meter->usage());
    }

    public function test_a_copy_called_from_a_tool_is_booked_apart_from_the_agent_that_called_it(): void
    {
        $meter = new UsageMeter();
        $large = $this->largeModel((new AssistantMessage('Sub'))->setUsage(new Usage(200, 20)));
        $agent = UsageMeter::watch(Agent::make()->setMessageStore(new InMemoryMessageStore())->setAiProvider(new FakeAIProvider(
            (new ToolCallMessage(null, [ToolCall::make('ask', 'call_1', [])]))->setUsage(new Usage(100, 10)),
            (new AssistantMessage('Done'))->setUsage(new Usage(100, 10)),
        )));
        $tool = new class ($agent, $large) extends Tool {
            public function __construct(protected Agent $parent, protected FakeAIProvider $other)
            {
            }

            public function __invoke(mixed ...$arguments): string
            {
                return (string) $this->parent->for('sub')->setAiProvider($this->other)->chat(new UserMessage('Hi'))->getMessage()?->getContent();
            }
        };
        $tool->setName('ask')->setDescription('Ask the large model');
        $agent->addTool($tool);

        $meter->during(fn (): mixed => $agent->for('main')->chat(new UserMessage('Hi')));

        $this->assertEqualsCanonicalizing(
            [new ModelUsage(FakeAIProvider::class, 'fake', 2, 200, 20), new ModelUsage($large::class, 'large', 1, 200, 20)],
            $meter->usage(),
        );
    }

    public function test_a_resume_on_another_provider_is_booked_under_that_provider(): void
    {
        $meter = new UsageMeter();
        $large = $this->largeModel((new AssistantMessage('Done'))->setUsage(new Usage(200, 20)));
        $agent = Agent::make();
        $agent->setMessageStore(new InMemoryMessageStore());
        $agent->setAiProvider(new FakeAIProvider((new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'x'])]))->setUsage(new Usage(100, 10))));
        $agent->addTool((new SearchTool())->requireApproval());
        $agent->setBranchRunner(new SequentialBranchRunner());
        $thread = UsageMeter::watch($agent)->for('thread');

        $state = $meter->during(fn (): mixed => $thread->chat(new UserMessage('Search')));
        $this->assertTrue($state->isInterrupted());

        $meter->during(fn (): mixed => $thread->setAiProvider($large)->submitInputs(['call_1' => 'approve'], new ApprovalTranslator())->run());

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10), new ModelUsage($large::class, 'large', 1, 200, 20)], $meter->usage());
    }

    public function test_watching_an_agent_that_only_its_copies_give_a_provider_does_not_fail_the_call(): void
    {
        $meter = new UsageMeter();
        $agent = UsageMeter::watch(Agent::make());
        $provider = new FakeAIProvider((new AssistantMessage('One'))->setUsage(new Usage(100, 10)));

        $meter->during(fn (): mixed => $agent->for('thread')->setAiProvider($provider)->chat(new UserMessage('Hi')));

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $meter->usage());
    }

    public function test_a_call_is_booked_under_the_provider_of_its_resources_not_the_one_the_agent_would_build(): void
    {
        $meter = new UsageMeter();
        $large = $this->largeModel((new AssistantMessage('One'))->setUsage(new Usage(200, 20)));
        $thread = UsageMeter::watch($this->agent())->for('thread');
        $thread->setResources(fn (): AgentResources => new AgentResources($large, $thread->getChatHistory(), new SystemMessage('Be brief')));

        $meter->during(fn (): mixed => $thread->chat(new UserMessage('Hi')));

        $this->assertEquals([new ModelUsage($large::class, 'large', 1, 200, 20)], $meter->usage());
    }

    public function test_an_agent_with_only_resources_has_a_provider_runs_with_no_error_and_is_counted(): void
    {
        $meter = new UsageMeter();
        $provider = new FakeAIProvider((new AssistantMessage('One'))->setUsage(new Usage(100, 10)));
        $thread = UsageMeter::watch(Agent::make())->for('thread');
        $thread->setResources(fn (): AgentResources => new AgentResources($provider, $thread->getChatHistory(), new SystemMessage('Be brief')));
        $errors = [];
        $thread->subscribe(WorkflowError::class, static function (WorkflowError $error) use (&$errors): void {
            $errors[] = $error;
        });

        $meter->during(fn (): mixed => $thread->chat(new UserMessage('Hi')));

        $this->assertSame([], $errors);
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $meter->usage());
    }

    public function test_watching_does_not_move_a_provider_hook_that_varies(): void
    {
        $meter = new UsageMeter();
        $large = $this->largeModel((new AssistantMessage('Two'))->setUsage(new Usage(200, 20)));
        $agent = new class () extends Agent {
            /** @var array<AIProviderInterface> */
            public array $pool = [];

            protected function provider(): AIProviderInterface
            {
                return array_shift($this->pool);
            }
        };
        $agent->pool = [new FakeAIProvider((new AssistantMessage('One'))->setUsage(new Usage(100, 10))), $large];
        $thread = UsageMeter::watch($agent)->for('thread');

        $first = $meter->during(fn (): mixed => $thread->chat(new UserMessage('Hi')));
        $second = $meter->during(fn (): mixed => $thread->chat(new UserMessage('Again')));

        $this->assertSame('One', $first->getMessage()?->getContent());
        $this->assertSame('Two', $second->getMessage()?->getContent());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10), new ModelUsage($large::class, 'large', 1, 200, 20)], $meter->usage());
    }

    public function test_no_usage_and_empty_usage_leave_nothing_measured(): void
    {
        $meter = new UsageMeter();
        $agent = UsageMeter::watch($this->agent(new AssistantMessage('One'), (new AssistantMessage('Two'))->setUsage(new Usage(0, 0))));

        $meter->during(fn (): mixed => $agent->for('a')->chat(new UserMessage('Hi')));
        $meter->during(fn (): mixed => $agent->for('b')->chat(new UserMessage('Hi')));

        $this->assertSame([], $meter->usage());
    }

    public function test_an_agent_nobody_watches_is_not_counted(): void
    {
        $meter = new UsageMeter();
        $agent = $this->agent((new AssistantMessage('One'))->setUsage(new Usage(100, 10)));

        $meter->during(fn (): mixed => $agent->for('thread')->chat(new UserMessage('Hi')));

        $this->assertSame([], $meter->usage());
    }

    protected function agent(Message ...$responses): Agent
    {
        return Agent::make()->setAiProvider(new FakeAIProvider(...$responses));
    }

    protected function largeModel(Message ...$responses): FakeAIProvider
    {
        return new class (...$responses) extends FakeAIProvider {
            public function getModel(): string
            {
                return 'large';
            }
        };
    }
}
