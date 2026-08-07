<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Trajectory;

use Closure;
use NeuronAI\Evaluation\AssertionResult;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Tools\ToolCall;

use function array_key_exists;
use function array_map;
use function count;
use function implode;
use function json_encode;

/**
 * Passes when the trajectory contains at least one call of the given tool,
 * optionally constrained on its arguments.
 */
class ToolWasCalled extends TrajectoryAssertion
{
    /**
     * @param array<string, mixed>|Closure|null $arguments Null = any arguments.
     *        An array is a subset match: every listed key must be present and
     *        strictly equal. A Closure `fn(array $inputs): bool` decides freely.
     */
    public function __construct(
        protected string $name,
        protected array|Closure|null $arguments = null,
    ) {
    }

    protected function evaluateTrajectory(Trajectory $trajectory): AssertionResult
    {
        $calls = $trajectory->toolCalls($this->name);

        if ($calls === []) {
            return AssertionResult::fail(
                0.0,
                "Expected tool '{$this->name}' to be called, but it was not ({$this->describeCalls($trajectory)})",
            );
        }

        foreach ($calls as $call) {
            if ($this->matchesArguments($call)) {
                return AssertionResult::pass(1.0);
            }
        }

        $actual = implode(', ', array_map(
            static fn (ToolCall $tool): string => (string) json_encode($tool->getInputs()),
            $calls
        ));

        return AssertionResult::fail(
            0.0,
            "Tool '{$this->name}' was called " . count($calls)
                . " time(s), but no call matched the argument constraint (arguments seen: {$actual})",
        );
    }

    protected function matchesArguments(ToolCall $call): bool
    {
        if ($this->arguments === null) {
            return true;
        }

        if ($this->arguments instanceof Closure) {
            return (bool) ($this->arguments)($call->getInputs());
        }

        $inputs = $call->getInputs();
        foreach ($this->arguments as $key => $value) {
            if (!array_key_exists($key, $inputs) || $inputs[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
