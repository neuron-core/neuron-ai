<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class ToolGetResultWithoutResultTest extends TestCase
{
    public function test_get_result_before_execution_throws_the_same_domain_error_as_tool_call(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'lookup';
        };

        $this->assertFalse($tool->hasResult());

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('lookup has no result');

        $tool->getResult();
    }
}
