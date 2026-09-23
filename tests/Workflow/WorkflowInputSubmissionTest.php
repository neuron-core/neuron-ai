<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\WaitForEventNode;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\PendingExecution;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function serialize;
use function method_exists;

use const INF;
use const NAN;

class WorkflowInputSubmissionTest extends TestCase
{
    protected function workflow(InMemoryPersistence $persistence): Workflow
    {
        return Workflow::make('signup')->setPersistence($persistence)->addNodes([
            new NodeOne(), new WaitForEventNode(), new NodeThree(),
        ]);
    }

    /** @return iterable<string, array{bool}> */
    public static function terminals(): iterable
    {
        yield 'run' => [false];
        yield 'events' => [true];
    }

    #[DataProvider('terminals')]
    public function test_custom_workflow_resumes_through_a_translator(bool $streaming): void
    {
        $persistence = new InMemoryPersistence();
        $state = $this->workflow($persistence)->run();
        $this->assertTrue($state->isInterrupted());
        $requestId = $state->getInterruptRequest()->getId();
        $before = serialize($persistence);

        $translator = $this->createMock(InputTranslatorInterface::class);
        $translator->expects($this->once())->method('translate')->willReturnCallback(
            function (array $payload, InterruptRequest $request) use ($requestId): array {
                $this->assertSame(['email' => 'user@example.com'], $payload);
                $this->assertInstanceOf(WaitForEventRequest::class, $request);
                $this->assertSame('user.signup', $request->getEventName());
                $this->assertSame($requestId, $request->getId());
                return ['registered' => $payload['email']];
            },
        );

        $workflow = $this->workflow($persistence);
        $submitted = $this->submit($workflow, $translator);
        $this->assertInstanceOf(PendingExecution::class, $submitted);
        $this->assertSame($before, serialize($persistence));
        self::assertFalse(method_exists($workflow, "getRunId"));

        if ($streaming) {
            $events = $submitted->events();
            $this->assertSame($before, serialize($persistence));
            iterator_to_array($events);
            $completed = $events->getReturn();
        } else {
            $completed = $submitted->run();
        }
        $this->assertFalse($completed->isInterrupted());
        $this->assertSame(['registered' => 'user@example.com'], $completed->get('received_payload'));
        $this->assertSame($state->getRunId(), $completed->getRunId());
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function nativeInputs(): iterable
    {
        foreach ([false, true] as $streaming) {
            $terminal = $streaming ? 'events' : 'run';
            yield $terminal . '-values' => [['email' => 'user@example.com', 'optional' => null, 'enabled' => false], $streaming];
            yield $terminal . '-empty' => [[], $streaming];
        }
    }

    #[DataProvider('nativeInputs')]
    public function test_native_inputs_resume_without_a_translator(array $payload, bool $streaming): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = $this->workflow($persistence);
        $started = $workflow->run();
        $before = serialize($persistence);
        $pending = $workflow->submitInputs($payload);
        $this->assertSame($before, serialize($persistence));

        if ($streaming) {
            $events = $pending->events();
            $this->assertSame($before, serialize($persistence));
            iterator_to_array($events);
            $completed = $events->getReturn();
        } else {
            $completed = $pending->run();
        }

        $this->assertFalse($completed->isInterrupted());
        $this->assertSame($started->getRunId(), $completed->getRunId());
        $this->assertSame($payload, $completed->get('received_payload'));
    }

    public function test_submission_uses_the_bound_identity_and_retains_its_idempotency_key(): void
    {
        $persistence = new InMemoryPersistence();
        $started = $this->workflow($persistence)->run();
        $workflow = Workflow::make()->setPersistence($persistence)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()])
            ->retainCompletionUntilAcknowledged();
        $translator = $this->createMock(InputTranslatorInterface::class);
        $translator->expects($this->once())->method('translate')->willReturn(['registered' => 'user@example.com']);

        $workflow->setWorkflowId('signup');
        $pending = $workflow->submitInputs(
            ['email' => 'user@example.com'],
            $translator,
            idempotencyKey: 'signup-response',
        );
        $completed = $pending->run();
        $this->assertFalse($completed->isInterrupted());
        $this->assertSame('signup', $completed->getWorkflowId());
        $this->assertSame($started->getRunId(), $completed->getRunId());
        $this->assertSame(['registered' => 'user@example.com'], $completed->get('received_payload'));

        $before = serialize($persistence);
        $events = $pending->events();
        $this->assertSame([], iterator_to_array($events));
        $this->assertEquals($completed, $events->getReturn());
        $this->assertSame($before, serialize($persistence));
    }

    /** @return iterable<string, array{array}> */
    public static function invalidInputs(): iterable
    {
        yield 'non-JSON payload' => [['value' => NAN]];
        yield 'infinite value' => [['value' => INF]];
    }

    public function test_pending_submissions_keep_their_payload_and_reject_a_replaced_run(): void
    {
        $persistence = new InMemoryPersistence();
        $workflow = $this->workflow($persistence);
        $firstRun = $workflow->run();
        $first = $workflow->submitInputs(['value' => 'first']);
        $second = $workflow->submitInputs(['value' => 'second']);

        $completed = $first->run();
        self::assertSame(['value' => 'first'], $completed->get('received_payload'));
        self::assertSame($firstRun->getRunId(), $completed->getRunId());

        $nextRun = $workflow->run();
        $before = serialize($persistence);
        try {
            $second->run();
            self::fail('A pending submission must not target a replacement run.');
        } catch (StaleWorkflowRunException) {
            self::assertSame($before, serialize($persistence));
            self::assertSame($nextRun->getRunId(), $workflow->inspect()?->runId);
        }
    }

    public function test_unbound_submission_does_not_generate_an_identity(): void
    {
        $workflow = Workflow::make();
        try {
            $workflow->submitInputs([]);
            self::fail('A submission needs a bound workflow with a persisted run.');
        } catch (InputTranslationException) {
            self::assertNull($workflow->getWorkflowId());
        }
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_payloads_leave_persistence_unchanged(array $inputs): void
    {
        $persistence = new InMemoryPersistence();
        $this->workflow($persistence)->run();
        $before = serialize($persistence);
        $workflow = $this->workflow($persistence);
        $request = \NeuronAI\Workflow\Executor\ExecutionRequest::resume($inputs);
        $this->assertSame($before, serialize($persistence));
        try {
            $workflow->run($request);
            $this->fail('Invalid payloads must fail before acceptance.');
        } catch (\NeuronAI\Exceptions\WorkflowException) {
            $this->assertSame($before, serialize($persistence));
        }
    }

    protected function submit(WorkflowInterface $workflow, InputTranslatorInterface $translator): PendingExecution
    {
        return $workflow->submitInputs(['email' => 'user@example.com'], $translator);
    }
}
