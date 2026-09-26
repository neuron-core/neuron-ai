<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\CompareDatesTool;
use NeuronAI\Tools\Toolkits\Calendar\IsDateInRangeTool;
use PHPUnit\Framework\TestCase;

class UnsupportedPrecisionTest extends TestCase
{
    public function test_compare_dates_rejects_a_precision_it_does_not_apply(): void
    {
        $tool = new CompareDatesTool();

        $tool->setInputs(['date1' => '2023-06-12 10:00:00', 'date2' => '2023-06-14 10:00:00', 'precision' => 'week'])->execute();

        $this->assertSame('Error: Unsupported precision: week', (string) $tool->getResult());
    }

    public function test_is_date_in_range_rejects_a_precision_it_does_not_apply(): void
    {
        $tool = new IsDateInRangeTool();

        $tool->setInputs(['date' => '2023-06-18 12:00:00', 'start_date' => '2023-06-12', 'end_date' => '2023-06-18', 'precision' => 'week'])->execute();

        $this->assertSame('Error: Unsupported precision: week', (string) $tool->getResult());
    }
}
