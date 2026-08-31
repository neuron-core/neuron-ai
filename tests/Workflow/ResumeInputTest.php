<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Resume\ResumeInput;
use NeuronAI\Workflow\Resume\ResumeKind;
use PHPUnit\Framework\TestCase;

use const NAN;

class ResumeInputTest extends TestCase
{
    public function testEventWireProtocolRoundTrips(): void
    {
        $wire = [
            'suspensionId' => 7,
            'kind' => 'event',
            'payload' => ['approved' => true],
        ];

        $input = ResumeInput::fromArray($wire);

        $this->assertSame(7, $input->suspensionId);
        $this->assertSame(ResumeKind::Event, $input->kind);
        $this->assertSame(['approved' => true], $input->payload);
        $this->assertSame($wire, $input->jsonSerialize());
    }

    public function testPayloadlessKindsUseTheirNamedFactories(): void
    {
        $this->assertSame(
            ['suspensionId' => 2, 'kind' => 'expired'],
            ResumeInput::expired(2)->jsonSerialize(),
        );
        $this->assertSame(
            ['suspensionId' => 3, 'kind' => 'timer'],
            ResumeInput::timer(3)->jsonSerialize(),
        );
    }

    public function testSuspensionIdMustBePositive(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('positive integer');

        ResumeInput::event(0, []);
    }

    public function testEventPayloadMustBeJsonCompatible(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('JSON-compatible');

        ResumeInput::event(1, ['invalid' => NAN]);
    }

    public function testUnknownWireKindIsRejected(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Unknown resume input kind');

        ResumeInput::fromArray(['suspensionId' => 1, 'kind' => 'unknown']);
    }
}
