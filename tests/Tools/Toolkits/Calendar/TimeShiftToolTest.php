<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\AddTimeTool;
use NeuronAI\Tools\Toolkits\Calendar\SubtractTimeTool;
use NeuronAI\Tools\Toolkits\Calendar\TimeShiftTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeShiftToolTest extends TestCase
{
    /**
     * @return array<string, array{TimeShiftTool, float, string, string}>
     */
    public static function fractions(): array
    {
        return [
            'minutes are rounded, not truncated' => [new AddTimeTool(), 2.3, 'minutes', '2023-01-01 12:02:18'],
            'hours are rounded, not truncated' => [new AddTimeTool(), 1.15, 'hours', '2023-01-01 13:09:00'],
            'a fraction of a week is kept' => [new AddTimeTool(), 1.5, 'weeks', '2023-01-12 00:00:00'],
            'a fraction of a week is kept when subtracting' => [new SubtractTimeTool(), 1.5, 'weeks', '2022-12-22 00:00:00'],
            'seconds round to the nearest second' => [new AddTimeTool(), 1.6, 'seconds', '2023-01-01 12:00:02'],
        ];
    }

    #[DataProvider('fractions')]
    public function test_the_fraction_of_a_fixed_length_unit_moves_the_date_by_seconds(TimeShiftTool $tool, float $amount, string $unit, string $expected): void
    {
        $this->assertSame($expected, $tool('2023-01-01 12:00:00', $amount, $unit));
    }

    /**
     * @return array<string, array{TimeShiftTool, string}>
     */
    public static function calendarUnits(): array
    {
        return [
            'months' => [new AddTimeTool(), 'months'],
            'years' => [new SubtractTimeTool(), 'years'],
        ];
    }

    #[DataProvider('calendarUnits')]
    public function test_a_fraction_of_a_calendar_unit_is_an_error(TimeShiftTool $tool, string $unit): void
    {
        $this->assertSame(
            'Error: Fractional amounts are only supported for seconds, minutes, hours, days and weeks.',
            $tool('2023-01-01', 1.5, $unit)
        );
    }

    public function test_a_negative_fraction_is_an_error_instead_of_no_change(): void
    {
        $this->assertSame('Error: The amount must not be negative.', (new AddTimeTool())('2023-01-01', -0.5, 'hours'));
    }

    public function test_months_overflow_like_php_date_arithmetic(): void
    {
        $this->assertSame('2023-03-03 00:00:00', (new AddTimeTool())('2023-01-31', 1, 'months'));
        $this->assertSame('2023-03-03 00:00:00', (new SubtractTimeTool())('2023-03-31', 1, 'months'));
    }
}
