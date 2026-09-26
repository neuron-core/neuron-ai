<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetDaysInMonthTool;
use NeuronAI\Tools\Toolkits\Calendar\IsLeapYearTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function str_contains;

class NumberPropertyIntegerParameterTest extends TestCase
{
    public function test_a_zero_padded_month_string_is_settled_without_a_type_error(): void
    {
        $tool = new GetDaysInMonthTool();

        $tool->setInputs(['month' => '02', 'year' => 2024])->execute();

        $result = $tool->getResult();
        $settledAsInvalidInput = $result instanceof ToolOutput && $result->isError();
        $acceptedAsFebruary = str_contains((string) $result, '"days_in_month":29');
        $this->assertTrue($settledAsInvalidInput || $acceptedAsFebruary, (string) $result);
    }

    public function test_a_fractional_month_is_returned_to_the_model_instead_of_crashing_the_run(): void
    {
        $tool = new GetDaysInMonthTool();

        $tool->setInputs(['month' => 2.5, 'year' => 2024])->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Parameter "month" must be of type integer, float given.', (string) $result);
    }

    public function test_a_fractional_year_is_returned_to_the_model_instead_of_crashing_the_run(): void
    {
        $tool = new IsLeapYearTool();

        $tool->setInputs(['year' => 2024.5])->execute();

        $result = $tool->getResult();
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('Parameter "year" must be of type integer, float given.', (string) $result);
    }

    public function test_a_whole_float_year_is_accepted(): void
    {
        $tool = new IsLeapYearTool();

        $tool->setInputs(['year' => 2000.0])->execute();

        $this->assertStringContainsString('"is_leap_year":true', (string) $tool->getResult());
    }
}
