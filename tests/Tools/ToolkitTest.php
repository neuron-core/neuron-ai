<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calculator\DivideTool;
use NeuronAI\Tools\Toolkits\Calculator\SumTool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

class ToolkitTest extends TestCase
{
    public function test_tool_exclude(): void
    {
        $toolkit = (new CalculatorToolkit());

        $toolsCount = \count($toolkit->tools());

        $toolkit = $toolkit->exclude([SumTool::class]);

        $this->assertEquals($toolsCount - 1, \count($toolkit->tools()));
        $this->assertNotContains(SumTool::class, \array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools()));
    }


    public function test_tools_exclude(): void
    {
        $toolkit = (new CalculatorToolkit());

        $toolsCount = \count($toolkit->tools());

        $toolkit = $toolkit->exclude([SumTool::class,DivideTool::class]);

        $this->assertEquals($toolsCount - 2, \count($toolkit->tools()));

        $toolClasses =  \array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools());
        $this->assertNotContains(SumTool::class, $toolClasses);
        $this->assertNotContains(DivideTool::class, $toolClasses);
    }


    public function test_tool_only(): void
    {
        $toolkit = (new CalculatorToolkit());

        $toolkit = $toolkit->only([SumTool::class]);

        $this->assertEquals(1, \count($toolkit->tools()));
        $this->assertContains(SumTool::class, \array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools()));
    }

    public function test_tools_only(): void
    {
        $toolkit = (new CalculatorToolkit());

        $toolkit = $toolkit->only([SumTool::class,DivideTool::class]);

        $this->assertEquals(2, \count($toolkit->tools()));

        $toolClasses =  \array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools());
        $this->assertContains(SumTool::class, $toolClasses);
        $this->assertContains(DivideTool::class, $toolClasses);
    }

    public function test_tools_combine_exclude_only(): void
    {
        $toolkit = new CalculatorToolkit();

        $toolkit = $toolkit->only([SumTool::class,DivideTool::class])->exclude([SumTool::class]);

        $this->assertEquals(1, \count($toolkit->tools()));

        $toolClasses =  \array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools());
        $this->assertContains(DivideTool::class, $toolClasses);


        $toolkit = (new CalculatorToolkit())
            ->only([SumTool::class,DivideTool::class])
            ->exclude([SumTool::class,DivideTool::class]);

        $this->assertEquals(0, \count($toolkit->tools()));
    }

    public function test_toolkit_with(): void
    {
        $toolkit = new CalculatorToolkit();

        $toolkit = $toolkit->only([SumTool::class]);

        $this->assertEquals(null, $toolkit->tools()[0]->getMaxTries());

        $toolkit = $toolkit->with(SumTool::class, fn (ToolInterface $tool): \NeuronAI\Tools\ToolInterface => $tool->setMaxTries(10));

        $this->assertEquals(10, $toolkit->tools()[0]->getMaxTries());
    }
}
