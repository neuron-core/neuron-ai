<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\SubtractTimeTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;

class SubtractTimeToolTest extends TestCase
{
    private SubtractTimeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new SubtractTimeTool();
    }

    public function test_subtract_seconds(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:30', 30, 'seconds');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_minutes(): void
    {
        $result = ($this->tool)('2023-01-01 12:45:00', 45, 'minutes');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_hours(): void
    {
        $result = ($this->tool)('2023-01-01 18:00:00', 6, 'hours');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_days(): void
    {
        $result = ($this->tool)('2023-01-06', 5, 'days');

        $this->assertEquals('2023-01-01 00:00:00', $result);
    }

    public function test_subtract_weeks(): void
    {
        $result = ($this->tool)('2023-01-15', 2, 'weeks');

        $this->assertEquals('2023-01-01 00:00:00', $result);
    }

    public function test_subtract_months(): void
    {
        $result = ($this->tool)('2023-04-15', 3, 'months');

        $this->assertEquals('2023-01-15 00:00:00', $result);
    }

    public function test_subtract_years(): void
    {
        $result = ($this->tool)('2023-02-28', 1, 'years');

        $this->assertEquals('2022-02-28 00:00:00', $result);
    }

    public function test_subtract_with_timestamp(): void
    {
        $timestamp = '1672617600'; // 2023-01-02 00:00:00 UTC
        $result = ($this->tool)($timestamp, 1, 'days');

        $this->assertEquals('2023-01-01 00:00:00', $result);
    }

    public function test_subtract_with_timezone(): void
    {
        $result = ($this->tool)('2023-01-02 00:00:00', 12, 'hours', 'America/New_York');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_with_custom_format(): void
    {
        $result = ($this->tool)('2023-01-02', 1, 'days', null, 'Y/m/d');

        $this->assertEquals('2023/01/01', $result);
    }

    public function test_subtract_float_amount(): void
    {
        $result = ($this->tool)('2023-01-01 13:30:00', 1.5, 'hours');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function test_subtract_across_month_boundary(): void
    {
        $result = ($this->tool)('2023-02-02', 5, 'days');

        $this->assertEquals('2023-01-28 00:00:00', $result);
    }

    public function test_subtract_across_year_boundary(): void
    {
        $result = ($this->tool)('2023-01-02', 3, 'days');

        $this->assertEquals('2022-12-30 00:00:00', $result);
    }

    public function test_subtract_from_leap_year_february(): void
    {
        $result = ($this->tool)('2024-02-29', 1, 'days'); // 2024 is leap year

        $this->assertEquals('2024-02-28 00:00:00', $result);
    }

    public function test_subtract_large_amount(): void
    {
        $result = ($this->tool)('2023-01-01', 365, 'days');

        $this->assertEquals('2022-01-01 00:00:00', $result);
    }

    public function test_invalid_date(): void
    {
        $result = ($this->tool)('invalid-date', 1, 'days');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_invalid_unit(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'invalid');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'days', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertEquals('subtract_time', $this->tool->getName());
        $this->assertEquals('Subtract time periods from a date (supports days, weeks, months, years, hours, minutes, seconds)', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(5, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('date', $propertyNames);
        $this->assertContains('amount', $propertyNames);
        $this->assertContains('unit', $propertyNames);
        $this->assertContains('timezone', $propertyNames);
        $this->assertContains('format', $propertyNames);
    }
}
