<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Resume;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Resume\ResumeKind;
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
        $this->assertSame(
            ['interruptId' => 2, 'kind' => 'expired'],
            ResumeInput::expired(2)->jsonSerialize(),
        );
        $this->assertSame(
            ['interruptId' => 3, 'kind' => 'timer'],
            ResumeInput::timer(3)->jsonSerialize(),
        );
    }

    public function test_interrupt_id_must_be_positive(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('positive integer');

        ResumeInput::event(0, []);
    }

    public function test_event_payload_must_be_json_compatible(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('JSON-compatible');

        ResumeInput::event(1, ['invalid' => NAN]);
    }

    public function test_unknown_wire_kind_is_rejected(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Unknown resume input kind');

        ResumeInput::fromArray(['interruptId' => 1, 'kind' => 'unknown']);
    }
}
