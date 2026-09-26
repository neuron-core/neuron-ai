<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calculator;

use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calculator\EvaluateTool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Toolkits\Calculator\MeanTool;
use NeuronAI\Tools\Toolkits\Calculator\StandardDeviationTool;
use NeuronAI\Tools\Toolkits\Calculator\VarianceTool;
use NeuronAI\Tools\Toolkits\TodoPlanning\WriteTodosTool;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
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

    public function test_provides_every_calculator_tool_once(): void
    {
        $this->assertSame(
            ['evaluate', 'factorial', 'combinations', 'permutations', 'gcd', 'lcm', 'mod_pow', 'is_prime', 'prime_factors', 'mean', 'median', 'mode', 'variance', 'standard_deviation'],
            $this->names(CalculatorToolkit::make())
        );
    }

    public function test_guidelines_only_name_tools_the_toolkit_provides(): void
    {
        $guidelines = (string) CalculatorToolkit::make()->guidelines();
        $names = $this->names(CalculatorToolkit::make());

        foreach (['evaluate', 'factorial', 'combinations', 'permutations', 'gcd', 'lcm', 'mod_pow', 'is_prime', 'prime_factors'] as $name) {
            $this->assertStringContainsString($name, $guidelines);
            $this->assertContains($name, $names);
        }
    }

    public function test_a_toolkit_has_no_guidelines_by_default(): void
    {
        $toolkit = new class () extends AbstractToolkit {
            public function provide(): array
            {
                return [];
            }
        };

        $this->assertNull($toolkit->guidelines());
        $this->assertSame([], $toolkit->tools());
    }

    public function test_filters_match_the_exact_class_not_its_subclasses(): void
    {
        $withoutVariance = CalculatorToolkit::make()->exclude([VarianceTool::class]);
        $onlyVariance = CalculatorToolkit::make()->only([VarianceTool::class]);

        $this->assertNotContains('variance', $this->names($withoutVariance));
        $this->assertContains('standard_deviation', $this->names($withoutVariance));
        $this->assertSame(['variance'], $this->names($onlyVariance));
    }

    public function test_classes_outside_the_toolkit_select_nothing_and_exclude_nothing(): void
    {
        $this->assertSame([], CalculatorToolkit::make()->only([WriteTodosTool::class])->tools());
        $this->assertCount(14, CalculatorToolkit::make()->exclude([WriteTodosTool::class])->tools());
    }

    public function test_with_can_replace_the_tool(): void
    {
        $replacement = EvaluateTool::make()->setName('calculate');

        $toolkit = CalculatorToolkit::make()->only([EvaluateTool::class])
            ->with(EvaluateTool::class, fn (ToolInterface $tool): ToolInterface => $replacement);

        $this->assertSame([$replacement], array_values($toolkit->tools()));
    }

    public function test_with_returning_null_keeps_the_configured_tool(): void
    {
        $toolkit = CalculatorToolkit::make()->only([EvaluateTool::class])
            ->with(EvaluateTool::class, function (ToolInterface $tool): void {
                $tool->setMaxRuns(3);
            });

        $tools = array_values($toolkit->tools());

        $this->assertInstanceOf(EvaluateTool::class, $tools[0]);
        $this->assertSame(3, $tools[0]->getMaxRuns());
    }

    public function test_with_only_touches_the_exact_class_that_survived_the_filters(): void
    {
        $configured = [];
        $toolkit = CalculatorToolkit::make()
            ->exclude([MeanTool::class])
            ->with(MeanTool::class, function (ToolInterface $tool) use (&$configured): ToolInterface {
                $configured[] = $tool->getName();
                return $tool;
            })
            ->with(VarianceTool::class, function (ToolInterface $tool) use (&$configured): ToolInterface {
                $configured[] = $tool->getName();
                return $tool->setMaxRuns(1);
            });

        $tools = $toolkit->tools();

        $this->assertSame(['variance'], $configured);
        foreach ($tools as $tool) {
            $this->assertSame($tool instanceof VarianceTool && !$tool instanceof StandardDeviationTool ? 1 : null, $tool->getMaxRuns());
        }
    }

    /**
     * @return string[]
     */
    protected function names(ToolkitInterface $toolkit): array
    {
        return array_values(array_map(fn (ToolInterface $tool): string => $tool->getName(), $toolkit->tools()));
    }
}
