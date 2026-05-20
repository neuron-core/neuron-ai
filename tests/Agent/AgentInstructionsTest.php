<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Middleware\TodoPlanning;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use PHPUnit\Framework\TestCase;

class AgentInstructionsTest extends TestCase
{
    // --- ToolSearchMiddleware ---

    public function test_tool_search_middleware_with_string_instructions(): void
    {
        $middleware = new ToolSearchMiddleware([]);
        $event = new AIInferenceEvent('Original instructions', []);

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsString($event->instructions);
        $this->assertStringContainsString('Original instructions', $event->instructions);
        $this->assertStringContainsString('tool_search', $event->instructions);
    }

    public function test_tool_search_middleware_with_array_instructions(): void
    {
        $middleware = new ToolSearchMiddleware([]);
        $event = new AIInferenceEvent(
            [new SystemContent('Original instructions')],
            []
        );

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsArray($event->instructions);
        $this->assertSame('Original instructions', $event->instructions[0]->content);
        $this->assertCount(2, $event->instructions);
        $this->assertStringContainsString('tool_search', $event->instructions[1]->content);
    }

    public function test_tool_search_middleware_custom_prompt_with_array_instructions(): void
    {
        $middleware = new ToolSearchMiddleware([], 'Custom search prompt.');
        $event = new AIInferenceEvent(
            [new SystemContent('Base')],
            []
        );

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsArray($event->instructions);
        $this->assertCount(2, $event->instructions);
        $this->assertSame('Custom search prompt.', $event->instructions[1]->content);
    }

    // --- TodoPlanning ---

    public function test_todo_planning_middleware_with_string_instructions(): void
    {
        $middleware = new TodoPlanning();
        $event = new AIInferenceEvent('Original instructions', []);

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsString($event->instructions);
        $this->assertStringContainsString('Original instructions', $event->instructions);
        $this->assertStringContainsString('write_todos', $event->instructions);
    }

    public function test_todo_planning_middleware_with_array_instructions(): void
    {
        $middleware = new TodoPlanning();
        $event = new AIInferenceEvent(
            [new SystemContent('Original instructions')],
            []
        );

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsArray($event->instructions);
        $this->assertSame('Original instructions', $event->instructions[0]->content);
        $this->assertCount(2, $event->instructions);
        $this->assertStringContainsString('write_todos', $event->instructions[1]->content);
    }

    public function test_todo_planning_custom_prompt_with_array_instructions(): void
    {
        $middleware = new TodoPlanning('Custom todo prompt.');
        $event = new AIInferenceEvent(
            [new SystemContent('Base')],
            []
        );

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsArray($event->instructions);
        $this->assertCount(2, $event->instructions);
        $this->assertSame('Custom todo prompt.', $event->instructions[1]->content);
    }

    // --- Multi-block preservation ---

    public function test_multiple_system_content_blocks_preserved(): void
    {
        $middleware = new ToolSearchMiddleware([]);
        $event = new AIInferenceEvent(
            [
                new SystemContent('Block one'),
                new SystemContent('Block two'),
            ],
            []
        );

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsArray($event->instructions);
        $this->assertCount(3, $event->instructions);
        $this->assertSame('Block one', $event->instructions[0]->content);
        $this->assertSame('Block two', $event->instructions[1]->content);
        $this->assertStringContainsString('tool_search', $event->instructions[2]->content);
    }
}
