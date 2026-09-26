<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use DateTimeImmutable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Executor\ActiveInterrupt;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An accepted answer is immutable until its node settles: a retry may repeat
 * it, never replace it.
 */
class ActiveInterruptTest extends TestCase
{
    protected WaitForEventRequest $request;

    protected function setUp(): void
    {
        $this->request = (new WaitForEventRequest('order.paid', new DateTimeImmutable('@1')))->withId(7);
    }

    public function test_the_first_answer_is_accepted_on_a_copy(): void
    {
        $waiting = new ActiveInterrupt($this->request, 'step-1');

        $answered = $waiting->withInput(ResumeInput::event($this->request, ['paid' => true]));

        $this->assertNotSame($waiting, $answered);
        $this->assertNull($waiting->input);
        $this->assertSame(['paid' => true], $answered->input->payload);
        $this->assertSame($this->request, $answered->request);
        $this->assertSame('step-1', $answered->stepId);
    }

    public function test_repeating_the_accepted_answer_is_idempotent(): void
    {
        $answered = (new ActiveInterrupt($this->request, 'step-1'))
            ->withInput(ResumeInput::event($this->request, ['paid' => true, 'amount' => 10.0]));

        $this->assertSame($answered, $answered->withInput(ResumeInput::event($this->request, ['paid' => true, 'amount' => 10.0])));
    }

    public function test_repeating_an_accepted_expiry_is_idempotent(): void
    {
        $expired = (new ActiveInterrupt($this->request, 'step-1'))->withInput(ResumeInput::expired($this->request));

        $this->assertSame($expired, $expired->withInput(ResumeInput::expired($this->request)));
    }

    public function test_an_accepted_expiry_cannot_become_a_timer_wake(): void
    {
        $expired = (new ActiveInterrupt($this->request, 'step-1'))->withInput(ResumeInput::expired($this->request));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Interrupt 7 already has an accepted input; its answer cannot change.');

        $expired->withInput(ResumeInput::timer($this->request));
    }

    #[DataProvider('conflictingAnswers')]
    public function test_a_different_answer_cannot_replace_the_accepted_one(ResumeInput $conflicting): void
    {
        $answered = (new ActiveInterrupt($this->request, 'step-1'))
            ->withInput(ResumeInput::event($this->request, ['paid' => true, 'amount' => 10]));

        $this->expectException(WorkflowException::class);
        $this->expectExceptionMessage('Interrupt 7 already has an accepted input; its answer cannot change.');

        $answered->withInput($conflicting);
    }

    /** @return array<string, array{ResumeInput}> */
    public static function conflictingAnswers(): array
    {
        $request = (new WaitForEventRequest('order.paid', new DateTimeImmutable('@1')))->withId(7);

        return [
            'other value' => [ResumeInput::event($request, ['paid' => false, 'amount' => 10])],
            'float for integer' => [ResumeInput::event($request, ['paid' => true, 'amount' => 10.0])],
            'numeric string for integer' => [ResumeInput::event($request, ['paid' => true, 'amount' => '10'])],
            'extra field' => [ResumeInput::event($request, ['paid' => true, 'amount' => 10, 'note' => 'x'])],
            'empty answer' => [ResumeInput::event($request, [])],
            'expiry' => [ResumeInput::expired($request)],
        ];
    }
}
