<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use NeuronAI\Tools\ToolOutput;

trait ToolErrorAssertions
{
    /**
     * Asserts a tool answered with a conversational failure carrying the given feedback.
     */
    protected function assertToolError(string $expectedFeedback, mixed $result): void
    {
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame($expectedFeedback, $result->getText());
    }
}
