<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Resume;

use DateTimeImmutable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\ResumeKind;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;
use const NAN;

class ResumeInputTest extends TestCase
{
    public function test_event_wire_protocol_round_trips(): void
    {
        $wire = [
            'interruptId' => 7,
            'kind' => 'event',
            'payload' => ['approved' => true],
        ];

        $input = ResumeInput::fromArray($wire);

        $this->assertSame(7, $input->interruptId);
        $this->assertSame(ResumeKind::Event, $input->kind);
        $this->assertSame(['approved' => true], $input->payload);
        $this->assertSame($wire, $input->jsonSerialize());
    }

    public function test_payloadless_kinds_use_their_named_factories(): void
    {
        $eventWait = (new WaitForEventRequest(
            'order.approved',
            new DateTimeImmutable('+1 hour'),
        ))->withId(2);
        $sleep = (new SleepUntilRequest(new DateTimeImmutable('+1 hour')))->withId(3);

        $this->assertSame(
            ['interruptId' => 2, 'kind' => 'expired'],
            ResumeInput::expired($eventWait)->jsonSerialize(),
        );
        $this->assertSame(
            ['interruptId' => 3, 'kind' => 'timer'],
            ResumeInput::timer($sleep)->jsonSerialize(),
        );
    }

    public function test_event_factory_accepts_the_interrupt_request(): void
    {
        $request = (new WaitForEventRequest('order.approved'))->withId(7);

        $input = ResumeInput::event($request, ['approved' => true]);

        $this->assertSame(7, $input->interruptId);
        $this->assertSame(ResumeKind::Event, $input->kind);
        $this->assertSame(['approved' => true], $input->payload);
    }

    public function test_interrupt_id_must_be_positive(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('positive integer');

        ResumeInput::fromArray([
            'interruptId' => 0,
            'kind' => 'event',
            'payload' => [],
        ]);
    }

    public function test_event_payload_must_be_json_compatible(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('JSON-compatible');

        $request = (new WaitForEventRequest('order.approved'))->withId(1);

        ResumeInput::event($request, ['invalid' => NAN]);
    }

    public function test_unknown_wire_kind_is_rejected(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Unknown resume input kind');

        ResumeInput::fromArray(['interruptId' => 1, 'kind' => 'unknown']);
    }
}
