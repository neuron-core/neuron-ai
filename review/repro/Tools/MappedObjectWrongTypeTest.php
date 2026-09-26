<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tests\Tools\Stub\Ticket;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MappedObjectWrongTypeTest extends TestCase
{
    protected function ticketTool(bool $nullable = false): Tool
    {
        $tool = new class () extends Tool {
            protected string $name = 'open_ticket';

            public bool $nullableTicket = false;

            protected function properties(): array
            {
                return [new ObjectProperty('ticket', required: true, class: Ticket::class, nullable: $this->nullableTicket)];
            }

            public function __invoke(?Ticket $ticket): string
            {
                return $ticket?->title ?? 'none';
            }
        };
        $tool->nullableTicket = $nullable;

        return $tool;
    }

    public static function scalars(): array
    {
        return [
            'string' => ['Fix the login', 'string'],
            'int' => [42, 'int'],
            'bool' => [true, 'bool'],
        ];
    }

    #[DataProvider('scalars')]
    public function test_a_scalar_for_a_mapped_object_is_tool_feedback(mixed $input, string $given): void
    {
        $tool = $this->ticketTool();

        $tool->setInputs(['ticket' => $input]);

        $this->assertFalse($tool->requiresApproval());
        $tool->execute();
        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString("Parameter \"ticket\" must be of type object, {$given} given.", $result->getText());
    }

    public function test_an_object_the_deserializer_rejects_is_tool_feedback(): void
    {
        $tool = $this->ticketTool();

        $tool->setInputs(['ticket' => ['title' => 'Fix the login', 'priority' => 'urgent']]);

        $this->assertFalse($tool->requiresApproval());
        $tool->execute();
        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('Parameter "ticket" ', $result->getText());
    }

    public function test_null_for_a_nullable_mapped_object_binds_null(): void
    {
        $tool = $this->ticketTool(nullable: true);

        $tool->setInputs(['ticket' => null])->execute();

        $this->assertSame('none', $tool->getResult());
    }
}
