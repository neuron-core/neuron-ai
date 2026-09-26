<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\TodoPlanning;

use NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;

class WriteTodosContractTest extends TestCase
{
    public function test_the_schema_requires_what_the_tool_requires(): void
    {
        $item = (new WriteTodosTool())->getInputSchema()['properties']['todos']['items'];

        $this->assertSame(['content', 'status'], $item['required']);
    }

    public function test_a_non_string_status_bound_through_the_tool_is_reported_without_php_warnings(): void
    {
        $warnings = [];
        set_error_handler(function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });

        try {
            $tool = new WriteTodosTool();
            $tool->setInputs(['todos' => [['content' => 'Plan', 'status' => ['pending']]]]);
            $tool->execute();
            $result = (string) $tool->getResult();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertStringStartsWith('Error: Todo at index 0 has invalid status', $result);
    }
}
