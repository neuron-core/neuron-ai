<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Trajectory;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Trajectory\Trajectory;
use NeuronAI\Tools\ToolDefinition;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

abstract class TrajectoryAssertionTestCase extends TestCase
{
    protected function makeTool(string $name, array $inputs = [], ?string $callId = null): ToolInterface
    {
        return ToolDefinition::make($name, "The {$name} tool")
            ->setInputs($inputs)
            ->setCallId($callId ?? "call_{$name}");
    }

    protected function trajectoryWithTools(ToolInterface ...$tools): Trajectory
    {
        return Trajectory::fromMessages([
            new UserMessage('Do the task'),
            new ToolCallMessage(null, $tools),
            new ToolResultMessage($tools),
            new AssistantMessage('Done.'),
        ]);
    }

    protected function emptyTrajectory(): Trajectory
    {
        return Trajectory::fromMessages([
            new UserMessage('Hello'),
            new AssistantMessage('Hi there.'),
        ]);
    }
}
