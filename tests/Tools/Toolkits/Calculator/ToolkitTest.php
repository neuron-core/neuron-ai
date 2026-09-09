<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;

class ToolkitTest extends TestCase
{
    public function test_tool_exclude(): void
    {
        $toolkit = (new CalculatorToolkit());

        $toolsCount = count($toolkit->tools());

        $toolkit = $toolkit->exclude([EvaluateTool::class]);

        $this->assertEquals($toolsCount - 1, count($toolkit->tools()));
        $this->assertNotContains(EvaluateTool::class, array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools()));
    }


    public function test_tools_exclude(): void
    {
        $toolkit = (new CalculatorToolkit());

        $toolsCount = count($toolkit->tools());

        $toolkit = $toolkit->exclude([EvaluateTool::class,MeanTool::class]);

        $this->assertEquals($toolsCount - 2, count($toolkit->tools()));

        $toolClasses =  array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools());
        $this->assertNotContains(EvaluateTool::class, $toolClasses);
        $this->assertNotContains(MeanTool::class, $toolClasses);
    }


    public function test_tool_only(): void
    {
        $toolkit = (new CalculatorToolkit());

        $toolkit = $toolkit->only([EvaluateTool::class]);

        $this->assertEquals(1, count($toolkit->tools()));
        $this->assertContains(EvaluateTool::class, array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools()));
    }

    public function test_tools_only(): void
    {
        $toolkit = (new CalculatorToolkit());

        $toolkit = $toolkit->only([EvaluateTool::class,MeanTool::class]);

        $this->assertEquals(2, count($toolkit->tools()));

        $toolClasses =  array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools());
        $this->assertContains(EvaluateTool::class, $toolClasses);
        $this->assertContains(MeanTool::class, $toolClasses);
    }

    public function test_tools_combine_exclude_only(): void
    {
        $toolkit = new CalculatorToolkit();

        $toolkit = $toolkit->only([EvaluateTool::class,MeanTool::class])->exclude([EvaluateTool::class]);

        $this->assertEquals(1, count($toolkit->tools()));

        $toolClasses =  array_map(fn (ToolInterface $tool): string => $tool::class, $toolkit->tools());
        $this->assertContains(MeanTool::class, $toolClasses);


        $toolkit = (new CalculatorToolkit())
            ->only([EvaluateTool::class,MeanTool::class])
            ->exclude([EvaluateTool::class,MeanTool::class]);

        $this->assertEquals(0, count($toolkit->tools()));
    }

    public function test_toolkit_with(): void
    {
        $toolkit = new CalculatorToolkit();

        $toolkit = $toolkit->only([EvaluateTool::class]);

        $this->assertEquals(null, $toolkit->tools()[0]->getMaxRuns());

        $toolkit = $toolkit->with(EvaluateTool::class, fn (ToolInterface $tool): \NeuronAI\Tools\ToolInterface => $tool->setMaxRuns(10));

        $this->assertEquals(10, $toolkit->tools()[0]->getMaxRuns());
    }
}
