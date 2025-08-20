<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\SubtractTimeTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

class SubtractTimeToolTest extends TestCase
{
    private SubtractTimeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new SubtractTimeTool();
    }

    public function testSubtractSeconds(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:30', 30, 'seconds');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function testSubtractMinutes(): void
    {
        $result = ($this->tool)('2023-01-01 12:45:00', 45, 'minutes');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function testSubtractHours(): void
    {
        $result = ($this->tool)('2023-01-01 18:00:00', 6, 'hours');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function testSubtractDays(): void
    {
        $result = ($this->tool)('2023-01-06', 5, 'days');

        $this->assertEquals('2023-01-01 00:00:00', $result);
    }

    public function testSubtractWeeks(): void
    {
        $result = ($this->tool)('2023-01-15', 2, 'weeks');

        $this->assertEquals('2023-01-01 00:00:00', $result);
    }

    public function testSubtractMonths(): void
    {
        $result = ($this->tool)('2023-04-15', 3, 'months');

        $this->assertEquals('2023-01-15 00:00:00', $result);
    }

    public function testSubtractYears(): void
    {
        $result = ($this->tool)('2023-02-28', 1, 'years');

        $this->assertEquals('2022-02-28 00:00:00', $result);
    }

    public function testSubtractWithTimestamp(): void
    {
        $timestamp = '1672617600'; // 2023-01-02 00:00:00 UTC
        $result = ($this->tool)($timestamp, 1, 'days');

        $this->assertEquals('2023-01-01 00:00:00', $result);
    }

    public function testSubtractWithTimezone(): void
    {
        $result = ($this->tool)('2023-01-02 00:00:00', 12, 'hours', 'America/New_York');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function testSubtractWithCustomFormat(): void
    {
        $result = ($this->tool)('2023-01-02', 1, 'days', null, 'Y/m/d');

        $this->assertEquals('2023/01/01', $result);
    }

    public function testSubtractFloatAmount(): void
    {
        $result = ($this->tool)('2023-01-01 13:30:00', 1.5, 'hours');

        $this->assertEquals('2023-01-01 12:00:00', $result);
    }

    public function testSubtractAcrossMonthBoundary(): void
    {
        $result = ($this->tool)('2023-02-02', 5, 'days');

        $this->assertEquals('2023-01-28 00:00:00', $result);
    }

    public function testSubtractAcrossYearBoundary(): void
    {
        $result = ($this->tool)('2023-01-02', 3, 'days');

        $this->assertEquals('2022-12-30 00:00:00', $result);
    }

    public function testSubtractFromLeapYearFebruary(): void
    {
        $result = ($this->tool)('2024-02-29', 1, 'days'); // 2024 is leap year

        $this->assertEquals('2024-02-28 00:00:00', $result);
    }

    public function testSubtractLargeAmount(): void
    {
        $result = ($this->tool)('2023-01-01', 365, 'days');

        $this->assertEquals('2022-01-01 00:00:00', $result);
    }

    public function testInvalidDate(): void
    {
        $result = ($this->tool)('invalid-date', 1, 'days');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testInvalidUnit(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'invalid');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testInvalidTimezone(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'days', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('subtract_time', $this->tool->getName());
        $this->assertEquals('Subtract time periods from a date (supports days, weeks, months, years, hours, minutes, seconds)', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(5, $properties);

        $propertyNames = \array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('date', $propertyNames);
        $this->assertContains('amount', $propertyNames);
        $this->assertContains('unit', $propertyNames);
        $this->assertContains('timezone', $propertyNames);
        $this->assertContains('format', $propertyNames);
    }
}
