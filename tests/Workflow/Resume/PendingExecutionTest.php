<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Resume;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use stdClass;

use function iterator_to_array;
use function serialize;

/**
 * A pending execution captures the answer and the run/attempt fences observed
 * when it was submitted, so a repeated delivery can never run twice.
 */
class PendingExecutionTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected stdClass $trace;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->trace = (object) ['answers' => []];
    }

    /**
     * Every answer is recorded and the node asks again until it receives
     * 'done', so the run stays suspended across several continuations.
     */
    protected function workflow(): Workflow
    {
        $node = new class ($this->trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $answer = $this->awaitEvent('answer');
                $this->trace->answers[] = $answer;
                if (($answer['value'] ?? null) !== 'done') {
                    $this->awaitEvent('answer');
                }

                return new StopEvent();
            }
        };

        return Workflow::make('pending')->setPersistence($this->persistence)->addNode($node);
    }

    public function test_submission_neither_executes_nor_writes(): void
    {
        $this->workflow()->run();
        $before = serialize($this->persistence);

        $pending = $this->workflow()->submitInputs(['value' => 'first']);
        $stream = $pending->events();

        $this->assertSame($before, serialize($this->persistence));
        $this->assertSame([], $this->trace->answers);
        iterator_to_array($stream);
        $this->assertSame([['value' => 'first']], $this->trace->answers);
    }

    public function test_run_delivers_the_submitted_answer(): void
    {
        $this->workflow()->run();

        $state = $this->workflow()->submitInputs(['value' => 'done'])->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame([['value' => 'done']], $this->trace->answers);
    }

    public function test_a_repeated_submission_of_the_same_observation_is_refused_as_stale(): void
    {
        $this->workflow()->run();
        $delivery = $this->workflow()->submitInputs(['value' => 'first']);
        $retry = $this->workflow()->submitInputs(['value' => 'first']);
        $this->assertTrue($delivery->run()->isInterrupted());
        $before = serialize($this->persistence);

        try {
            $retry->run();
            $this->fail('A retried delivery must not answer the next interruption.');
        } catch (WorkflowException $e) {
            $this->assertSame(
                "Stale continuation for workflow ID 'pending': expected execution attempt 1, current attempt is 2.",
                $e->getMessage(),
            );
        }

        $this->assertSame($before, serialize($this->persistence));
        $this->assertSame([['value' => 'first']], $this->trace->answers);
    }

    public function test_a_submission_made_after_the_run_advanced_answers_the_current_interruption(): void
    {
        $this->workflow()->run();
        $this->workflow()->submitInputs(['value' => 'first'])->run();

        $state = $this->workflow()->submitInputs(['value' => 'done'])->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame([['value' => 'first'], ['value' => 'done']], $this->trace->answers);
    }

    public function test_the_translator_sees_the_current_interruption_and_its_result_is_delivered(): void
    {
        $this->workflow()->run();
        $translator = new class () implements InputTranslatorInterface {
            public ?InterruptRequest $seen = null;

            public function translate(array $payload, InterruptRequest $request): array
            {
                $this->seen = $request;

                return ['value' => $payload['external']];
            }
        };

        $this->workflow()->submitInputs(['external' => 'done'], $translator)->run();

        $this->assertSame(1, $translator->seen?->getId());
        $this->assertSame([['value' => 'done']], $this->trace->answers);
    }
}
