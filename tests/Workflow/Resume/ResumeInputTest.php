<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Resume;

use DateTimeImmutable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\ResumeType;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use const INF;
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
        $this->assertSame(ResumeType::Event, $input->kind);
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
        $this->assertSame(ResumeType::Event, $input->kind);
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

    /** @param array<string, mixed> $payload */
    #[DataProvider('nonJsonPayloads')]
    public function test_event_payload_must_be_json_compatible(array $payload): void
    {
        $request = (new WaitForEventRequest('order.approved'))->withId(1);

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('A resume event payload must be JSON-compatible.');

        ResumeInput::event($request, $payload);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function nonJsonPayloads(): array
    {
        return [
            'NAN' => [['invalid' => NAN]],
            'infinity' => [['invalid' => INF]],
            'nested NAN' => [['outer' => ['inner' => [NAN]]]],
            'invalid UTF-8' => [['name' => "\xB1\x31"]],
        ];
    }

    public function test_unknown_wire_kind_is_rejected(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Unknown resume input kind');

        ResumeInput::fromArray(['interruptId' => 1, 'kind' => 'unknown']);
    }

    /** @param array<string, mixed> $wire */
    #[DataProvider('malformedWireInputs')]
    public function test_malformed_wire_input_is_rejected(array $wire, string $message): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage($message);

        ResumeInput::fromArray($wire);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function malformedWireInputs(): array
    {
        $notAnInteger = 'Resume input interruptId must be an integer.';

        return [
            'missing id' => [['kind' => 'event', 'payload' => []], $notAnInteger],
            'numeric string id' => [['interruptId' => '7', 'kind' => 'event', 'payload' => []], $notAnInteger],
            'float id' => [['interruptId' => 7.0, 'kind' => 'event', 'payload' => []], $notAnInteger],
            'null id' => [['interruptId' => null, 'kind' => 'timer'], $notAnInteger],
            'negative id' => [['interruptId' => -7, 'kind' => 'timer'], 'An interrupt ID must be a positive integer.'],
            'missing kind' => [['interruptId' => 7], 'Unknown resume input kind.'],
            'kind in another case' => [['interruptId' => 7, 'kind' => 'EVENT', 'payload' => []], 'Unknown resume input kind.'],
            'event without payload' => [['interruptId' => 7, 'kind' => 'event'], 'A resume event input requires an array payload.'],
            'event with null payload' => [['interruptId' => 7, 'kind' => 'event', 'payload' => null], 'A resume event input requires an array payload.'],
            'event with scalar payload' => [['interruptId' => 7, 'kind' => 'event', 'payload' => 'yes'], 'A resume event input requires an array payload.'],
            'event with non-JSON payload' => [['interruptId' => 7, 'kind' => 'event', 'payload' => ['n' => NAN]], 'A resume event payload must be JSON-compatible.'],
        ];
    }

    #[DataProvider('payloadlessKinds')]
    public function test_payloadless_kinds_drop_a_supplied_payload(string $kind, ResumeType $type): void
    {
        $input = ResumeInput::fromArray(['interruptId' => 3, 'kind' => $kind, 'payload' => ['forged' => true]]);

        $this->assertSame($type, $input->kind);
        $this->assertNull($input->payload);
        $this->assertSame(['interruptId' => 3, 'kind' => $kind], $input->jsonSerialize());
    }

    /** @return array<string, array{string, ResumeType}> */
    public static function payloadlessKinds(): array
    {
        return [
            'expired' => ['expired', ResumeType::Expired],
            'timer' => ['timer', ResumeType::Timer],
        ];
    }

    public function test_an_empty_event_payload_stays_an_answer(): void
    {
        $input = ResumeInput::fromArray(['interruptId' => 1, 'kind' => 'event', 'payload' => []]);

        $this->assertSame([], $input->payload);
        $this->assertSame(['interruptId' => 1, 'kind' => 'event', 'payload' => []], $input->jsonSerialize());
    }

    public function test_an_unactivated_request_cannot_be_answered(): void
    {
        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('The interrupt request has not been activated yet.');

        ResumeInput::event(new WaitForEventRequest('order.approved'), []);
    }
}
