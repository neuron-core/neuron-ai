<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingWaitNode;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AcceptedResumeInputTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
    }

    protected function workflow(bool $fail = false): Workflow
    {
        return Workflow::make('accepted-input')
            ->setPersistence($this->persistence)
            ->addNode(new MemoizingWaitNode($fail));
    }

    /** @param array<string, mixed> $payload */
    protected function failAfterAcceptingInput(array $payload = ['answer' => 'original']): InterruptRequest
    {
        $request = $this->workflow()->run()->getInterruptRequest();
        self::assertNotNull($request);

        try {
            $this->workflow(true)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume($payload));
            self::fail('Expected the node to fail after accepting its input.');
        } catch (RuntimeException $e) {
            self::assertSame('Failed after memoizing the accepted answer.', $e->getMessage());
        }

        return $request;
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('acceptedPayloadProvider')]
    public function test_duplicate_delivery_recovers_with_the_accepted_answer(array $payload): void
    {
        $this->failAfterAcceptingInput($payload);
        $state = $this->workflow()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume($payload));

        self::assertEquals($payload, $state->get('payload'));
        self::assertEquals($state->get('payload'), $state->get('memo'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function acceptedPayloadProvider(): array
    {
        return [
            'string' => [['answer' => 'original']],
            'empty' => [[]],
            'nested-object' => [['answer' => (object) ['value' => 42]]],
        ];
    }

    public function test_inputless_recovery_reuses_the_accepted_answer(): void
    {
        $this->failAfterAcceptingInput();
        $state = $this->workflow()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());

        self::assertSame(['answer' => 'original'], $state->get('payload'));
        self::assertSame($state->get('payload'), $state->get('memo'));
    }

    public function test_conflicting_input_preserves_the_accepted_answer(): void
    {
        $this->failAfterAcceptingInput();
        $control = $this->persistence->get('accepted-input', '__control');

        try {
            $this->workflow()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['answer' => 'replacement']));
            self::fail('Expected conflicting resume input to be rejected.');
        } catch (WorkflowException $e) {
            self::assertSame('Interrupt 1 already has an accepted input; its answer cannot change.', $e->getMessage());
        }

        self::assertSame($control, $this->persistence->get('accepted-input', '__control'));
        $state = $this->workflow()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());
        self::assertSame(['answer' => 'original'], $state->get('payload'));
        self::assertSame($state->get('payload'), $state->get('memo'));
    }

}
