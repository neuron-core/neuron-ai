<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Runner;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\BaseEvaluator;
use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Contracts\DatasetInterface;
use NeuronAI\Evaluation\Contracts\EvaluatorInterface;
use NeuronAI\Evaluation\Conversation\UserSimulator;
use NeuronAI\Evaluation\Dataset\ArrayDataset;
use NeuronAI\Evaluation\Runner\EvaluationResults;
use NeuronAI\Evaluation\Runner\EvaluatorResult;
use NeuronAI\Evaluation\Runner\EvaluatorRunner;
use NeuronAI\Evaluation\Usage\ModelUsage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Evaluation\Cache\Stub\CountingEvaluator;
use NeuronAI\Tests\Evaluation\Stub\ConversationEvaluator;
use NeuronAI\Tests\Evaluation\Stub\FailingItemEvaluator;
use NeuronAI\Tests\Evaluation\Stub\MeteredEvaluator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function glob;
use function is_dir;
use function json_encode;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function usleep;

use const JSON_THROW_ON_ERROR;

/**
 * What the runner measures per item: the time of run() and of evaluate(),
 * and what each consumed.
 */
class EvaluatorRunnerMeasurementTest extends TestCase
{
    protected string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir() . '/' . uniqid('neuron-measurement-');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDirectory)) {
            foreach (glob($this->cacheDirectory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->cacheDirectory);
        }
    }

    public function test_run_and_evaluate_are_timed_apart_and_inside_the_total(): void
    {
        $evaluator = new class () extends BaseEvaluator {
            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([['name' => 'a']]);
            }

            public function run(array $datasetItem): mixed
            {
                usleep(40_000);

                return 'done';
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
                usleep(5_000);
            }
        };

        $result = (new EvaluatorRunner())->run($evaluator)->getResults()[0];

        $this->assertGreaterThanOrEqual(0.04, $result->getRunTime());
        $this->assertGreaterThanOrEqual(0.005, $result->getEvaluationTime());
        $this->assertLessThan($result->getRunTime(), $result->getEvaluationTime());
        $this->assertLessThanOrEqual($result->getExecutionTime(), $result->getRunTime() + $result->getEvaluationTime());
    }

    public function test_a_cached_item_has_no_run_time_and_measures_no_run_usage(): void
    {
        $evaluator = fn (): MeteredEvaluator => new MeteredEvaluator(
            $this->agent($this->answer(100, 10), $this->answer(100, 10)),
            [['name' => 'a']],
        );
        $runner = (new EvaluatorRunner())->withCache(new FileEvaluationCache($this->cacheDirectory));

        $first = $runner->run($evaluator())->getResults()[0];
        $second = $runner->run($evaluator())->getResults()[0];

        $this->assertNotSame([], $first->getRunUsage());
        $this->assertTrue($second->isCachedRun());
        $this->assertNull($second->getRunTime());
        $this->assertSame([], $second->getRunUsage());
        $this->assertIsFloat($second->getEvaluationTime());
    }

    public function test_an_item_that_fails_in_run_keeps_its_run_time_and_has_no_evaluation_time(): void
    {
        $result = (new EvaluatorRunner())->run(new FailingItemEvaluator([['name' => 'a', 'fail' => 'run']]))->getResults()[0];

        $this->assertIsFloat($result->getRunTime());
        $this->assertNull($result->getEvaluationTime());
    }

    public function test_an_item_that_fails_in_evaluate_keeps_both_times(): void
    {
        $result = (new EvaluatorRunner())->run(new FailingItemEvaluator([['name' => 'a', 'fail' => 'evaluate']]))->getResults()[0];

        $this->assertIsFloat($result->getRunTime());
        $this->assertIsFloat($result->getEvaluationTime());
    }

    public function test_each_item_reports_its_own_usage_when_the_evaluator_meters_one_agent_on_every_item(): void
    {
        $evaluator = new MeteredEvaluator(
            $this->agent($this->answer(100, 10), $this->answer(200, 20)),
            [['name' => 'a'], ['name' => 'b']],
        );

        $results = (new EvaluatorRunner())->run($evaluator)->getResults();

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $results[0]->getRunUsage());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 200, 20)], $results[1]->getRunUsage());
    }

    public function test_the_usage_of_a_run_that_threw_is_reported_and_does_not_reach_the_next_item(): void
    {
        $evaluator = new MeteredEvaluator(
            $this->agent($this->answer(100, 10), $this->answer(200, 20)),
            [['name' => 'a', 'fail' => true], ['name' => 'b']],
        );

        $results = (new EvaluatorRunner())->run($evaluator)->getResults();

        $this->assertTrue($results[0]->hasError());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $results[0]->getRunUsage());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 200, 20)], $results[1]->getRunUsage());
    }

    public function test_the_agent_and_the_judges_are_reported_apart(): void
    {
        $verdict = (new AssistantMessage(json_encode(['score' => 0.9, 'reasoning' => 'Fine.'], JSON_THROW_ON_ERROR)))->setUsage(new Usage(50, 5));
        $evaluator = new MeteredEvaluator($this->agent($this->answer(100, 10)), [['name' => 'a']], $this->agent($verdict));

        $result = (new EvaluatorRunner())->run($evaluator)->getResults()[0];

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $result->getRunUsage());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 50, 5)], $result->getJudgeUsage());
    }

    public function test_a_conversation_counts_itself_when_run_returns_its_trajectory(): void
    {
        $evaluator = new ConversationEvaluator($this->agent($this->answer(100, 10), $this->answer(200, 20)), ['One', 'Two']);

        $result = (new EvaluatorRunner())->run($evaluator)->getResults()[0];

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 2, 300, 30)], $result->getRunUsage());
    }

    public function test_a_conversation_counts_itself_when_run_returns_something_else(): void
    {
        $evaluator = new ConversationEvaluator($this->agent($this->answer(100, 10)), ['One'], finalAnswer: true);

        $result = (new EvaluatorRunner())->run($evaluator)->getResults()[0];

        $this->assertSame('Done', $result->getOutput());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $result->getRunUsage());
    }

    public function test_the_turns_of_a_conversation_that_threw_are_reported(): void
    {
        // The provider has nothing to answer the second turn with
        $evaluator = new ConversationEvaluator($this->agent($this->answer(100, 10)), ['One', 'Two']);

        $result = (new EvaluatorRunner())->run($evaluator)->getResults()[0];

        $this->assertTrue($result->hasError());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $result->getRunUsage());
    }

    public function test_a_conversation_counts_its_simulated_user_and_leaves_the_shared_one_as_it_was(): void
    {
        $turn = fn (bool $stop): AssistantMessage => (new AssistantMessage(json_encode(['stop' => $stop, 'message' => $stop ? null : 'Hello', 'reason' => null], JSON_THROW_ON_ERROR)))->setUsage(new Usage(10, 1));
        $simulator = UserSimulator::make()->withPersona('A customer')->withGoal('Say hello');
        $simulator->setAiProvider(new FakeAIProvider($turn(false), $turn(true)));
        $evaluator = new ConversationEvaluator($this->agent($this->answer(100, 20)), [], simulator: $simulator);

        $result = (new EvaluatorRunner())->run($evaluator)->getResults()[0];

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 3, 120, 22)], $result->getRunUsage());
        $this->assertNull((new ReflectionProperty($simulator, 'listeners'))->getValue($simulator));
    }

    public function test_metering_the_agent_of_a_conversation_counts_it_once_and_another_agent_is_added(): void
    {
        $other = $this->agent($this->answer(1, 1));
        $evaluator = new ConversationEvaluator($this->agent($this->answer(100, 10)), ['One'], meterAgent: true, also: $other);

        $result = (new EvaluatorRunner())->run($evaluator)->getResults()[0];

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 2, 101, 11)], $result->getRunUsage());
    }

    public function test_a_cached_conversation_measures_no_run_usage_though_its_output_was_counted_once(): void
    {
        $runner = (new EvaluatorRunner())->withCache(new FileEvaluationCache($this->cacheDirectory));
        $evaluator = fn (): ConversationEvaluator => new ConversationEvaluator($this->agent($this->answer(100, 10)), ['One']);

        $this->assertNotSame([], $runner->run($evaluator())->getResults()[0]->getRunUsage());

        $second = $runner->run($evaluator())->getResults()[0];

        $this->assertTrue($second->isCachedRun());
        $this->assertSame([], $second->getRunUsage());
    }

    public function test_judges_that_answered_before_evaluate_threw_are_reported(): void
    {
        $verdict = (new AssistantMessage(json_encode(['score' => 0.9, 'reasoning' => 'Fine.'], JSON_THROW_ON_ERROR)))->setUsage(new Usage(50, 5));
        $evaluator = new MeteredEvaluator($this->agent($this->answer(100, 10)), [['name' => 'a', 'throw_in_evaluate' => true]], $this->agent($verdict));

        $result = (new EvaluatorRunner())->run($evaluator)->getResults()[0];

        $this->assertTrue($result->hasError());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 50, 5)], $result->getJudgeUsage());
    }

    public function test_an_agent_metered_once_in_set_up_is_counted_on_every_item(): void
    {
        $agent = $this->agent($this->answer(100, 10), $this->answer(200, 20));
        $evaluator = new class ($agent) extends BaseEvaluator {
            public function __construct(protected AgentInterface $agent)
            {
                parent::__construct();
            }

            public function setUp(): void
            {
                $this->agent = $this->metered($this->agent);
            }

            public function getDataset(): DatasetInterface
            {
                return new ArrayDataset([['name' => 'a'], ['name' => 'b']]);
            }

            public function run(array $datasetItem): mixed
            {
                return $this->agent->for($datasetItem['name'])->chat(new UserMessage('Hi'))->getMessage()?->getContent();
            }

            public function evaluate(mixed $output, array $datasetItem): void
            {
            }
        };

        $results = (new EvaluatorRunner())->run($evaluator)->getResults();

        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 100, 10)], $results[0]->getRunUsage());
        $this->assertEquals([new ModelUsage(FakeAIProvider::class, 'fake', 1, 200, 20)], $results[1]->getRunUsage());
    }

    public function test_a_subclass_that_overrides_run_item_with_its_published_signature_still_loads(): void
    {
        $runner = new class () extends EvaluatorRunner {
            public int $items = 0;

            protected function runItem(EvaluatorInterface $evaluator, int $index, array $item, bool $forked = false): EvaluatorResult
            {
                $this->items++;

                return parent::runItem($evaluator, $index, $item, $forked);
            }
        };

        $runner->run(new CountingEvaluator());

        $this->assertSame(2, $runner->items);
    }

    public function test_the_average_run_time_leaves_out_cached_items(): void
    {
        $results = new EvaluationResults([
            new EvaluatorResult('App\\Eval', 0, true, [], 'a', 1.0, 1, 0, runTime: 0.5, evaluationTime: 0.25),
            new EvaluatorResult('App\\Eval', 1, true, [], 'b', 1.0, 1, 0, cachedRun: true, evaluationTime: 0.75),
        ]);

        $this->assertSame(0.5, $results->getAverageRunTime());
        $this->assertSame(0.5, $results->getAverageEvaluationTime());
    }

    public function test_the_averages_of_nothing_measured_are_null(): void
    {
        $this->assertNull((new EvaluationResults([]))->getAverageRunTime());
        $this->assertNull((new EvaluationResults([]))->getAverageEvaluationTime());
    }

    public function test_the_averages_ignore_the_items_that_did_not_run(): void
    {
        $results = (new EvaluatorRunner())->run(new FailingItemEvaluator([
            ['name' => 'a', 'fail' => 'run'],
            ['name' => 'b'],
        ]));

        $this->assertEqualsWithDelta($results->getResults()[1]->getEvaluationTime(), $results->getAverageEvaluationTime(), 1e-12);
    }

    protected function agent(Message ...$responses): Agent
    {
        return Agent::make()->setAiProvider(new FakeAIProvider(...$responses));
    }

    protected function answer(int $inputTokens, int $outputTokens): AssistantMessage
    {
        return (new AssistantMessage('Done'))->setUsage(new Usage($inputTokens, $outputTokens));
    }
}
