<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\WaitForEventNode;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\ResumeInput;
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
                return [ResumeInput::event($request, ['registered' => $payload['email']])];
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

    protected function submit(WorkflowInterface $workflow, InputTranslatorInterface $translator): WorkflowInterface
    {
        return $workflow->submitInputs(['email' => 'user@example.com'], $translator);
    }
}
