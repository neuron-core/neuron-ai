<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasApproved;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasCalled;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasNotCalled;
use NeuronAI\Evaluation\Assertions\Trajectory\ToolWasRejected;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryAssertion;
use NeuronAI\Evaluation\Assertions\Trajectory\TrajectoryMatches;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The type guard shared by every trajectory assertion: anything but a
 * Trajectory is a coding error in the evaluator, never a failed verdict.
 */
class TrajectoryAssertionTest extends TestCase
{
    /**
     * @return iterable<string, array{TrajectoryAssertion, mixed, string}>
     */
    public static function nonTrajectoryInputs(): iterable
    {
        $assertions = [
            'ToolWasCalled' => new ToolWasCalled('search'),
            'ToolWasNotCalled' => new ToolWasNotCalled('search'),
            'ToolWasApproved' => new ToolWasApproved('search'),
            'ToolWasRejected' => new ToolWasRejected('search'),
            'TrajectoryMatches' => new TrajectoryMatches(['search']),
        ];
        $inputs = [
            'final answer string' => ['Done.', 'string'],
            'null' => [null, 'null'],
            'message list' => [[new UserMessage('Hello')], 'array'],
            'chat history' => [new ChatHistory(new InMemoryMessageStore(), 'thread'), ChatHistory::class],
        ];

        foreach ($assertions as $assertionName => $assertion) {
            foreach ($inputs as $inputName => [$input, $type]) {
                yield "{$assertionName} with {$inputName}" => [$assertion, $input, $type];
            }
        }
    }

    #[DataProvider('nonTrajectoryInputs')]
    public function test_non_trajectory_input_is_rejected_with_the_offending_type(TrajectoryAssertion $assertion, mixed $input, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($assertion::class . " evaluates a Trajectory, got {$type}");

        $assertion->evaluate($input);
    }
}
