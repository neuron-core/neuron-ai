<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\WaitForEventNode;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
            function (array $payload, array $requests) use ($requestId): array {
                $this->assertSame(['email' => 'user@example.com'], $payload);
                $this->assertCount(1, $requests);
                $request = array_values($requests)[0];
                $this->assertInstanceOf(WaitForEventRequest::class, $request);
                $this->assertSame('user.signup', $request->getEventName());
                $this->assertSame($requestId, $request->getId());
                return [$request->getId() => ['registered' => $payload['email']]];
            },
        );

        $workflow = $this->workflow($persistence);
        $submitted = $this->submit($workflow, $translator);
        $this->assertSame($workflow, $submitted);
        $this->assertSame($before, serialize($persistence));
        $this->assertNull($workflow->getRunId());

        if ($streaming) {
            $events = $submitted->events();
            iterator_to_array($events);
            $completed = $events->getReturn();
        } else {
            $completed = $submitted->run();
        }
        $this->assertFalse($completed->isInterrupted());
        $this->assertSame(['registered' => 'user@example.com'], $completed->get('received_payload'));
        $this->assertSame($state->getRunId(), $completed->getRunId());
    }

    /** @return iterable<string, array{array}> */
    public static function invalidInputs(): iterable
    {
        yield 'unaddressed payload' => [['registered' => true]];
        yield 'zero ID' => [[0 => []]];
        yield 'negative ID' => [[-1 => []]];
        yield 'missing payload' => [[1 => null]];
        yield 'scalar payload' => [[1 => 'answer']];
        yield 'malformed batch' => [[1 => ['registered' => true], 2 => null]];
        yield 'non-JSON payload' => [[1 => ['value' => NAN]]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_addressed_payloads_leave_persistence_unchanged(array $inputs): void
    {
        $persistence = new InMemoryPersistence();
        $this->workflow($persistence)->run();
        $before = serialize($persistence);
        $workflow = $this->workflow($persistence);
        $this->assertSame($workflow, $workflow->resume($inputs));
        $this->assertSame($before, serialize($persistence));
        try {
            $workflow->run();
            $this->fail('Invalid addressed payloads must fail before acceptance.');
        } catch (\NeuronAI\Exceptions\WorkflowException) {
            $this->assertSame($before, serialize($persistence));
        }
    }

    protected function submit(WorkflowInterface $workflow, InputTranslatorInterface $translator): WorkflowInterface
    {
        return $workflow->submitInputs(['email' => 'user@example.com'], $translator);
    }
}
