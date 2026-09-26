<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\Calculator\FactorialTool;
use NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class NullRequiredInputTest extends TestCase
{
    public function test_null_for_a_required_non_nullable_integer_is_settled_as_tool_feedback(): void
    {
        $tool = (new FactorialTool())->setInputs(['n' => null]);

        $this->assertFalse($tool->requiresApproval());

        $tool->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Parameter "n" must be of type integer, null given.', $result->getText());
    }

    public function test_null_for_a_required_non_nullable_array_is_settled_as_tool_feedback(): void
    {
        $tool = (new WriteTodosTool())->setInputs(['todos' => null]);

        $tool->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Parameter "todos" must be of type array, null given.', $result->getText());
    }

    public function test_null_for_a_required_nullable_property_reaches_invoke(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'nullable_tool';

            protected function properties(): array
            {
                return [new ToolProperty('value', PropertyType::INTEGER, required: true, nullable: true)];
            }

            public function __invoke(?int $value): string
            {
                return $value === null ? 'null' : (string) $value;
            }
        };

        $tool->setInputs(['value' => null])->execute();

        $this->assertSame('null', $tool->getResult());
    }

    public function test_null_for_an_optional_property_reaches_invoke_as_null(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'optional_tool';

            protected function properties(): array
            {
                return [new ToolProperty('value', PropertyType::INTEGER)];
            }

            public function __invoke(?int $value = null): string
            {
                return $value === null ? 'null' : (string) $value;
            }
        };

        $tool->setInputs(['value' => null])->execute();

        $this->assertSame('null', $tool->getResult());
    }
}
