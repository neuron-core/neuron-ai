<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\AddTimeTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

class AddTimeToolTest extends TestCase
{
    private AddTimeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new AddTimeTool();
    }

    public function testAddSeconds(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 30, 'seconds');

        $this->assertEquals('2023-01-01 12:00:30', $result);
    }

    public function testAddMinutes(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 45, 'minutes');

        $this->assertEquals('2023-01-01 12:45:00', $result);
    }

    public function testAddHours(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 6, 'hours');

        $this->assertEquals('2023-01-01 18:00:00', $result);
    }

    public function testAddDays(): void
    {
        $result = ($this->tool)('2023-01-01', 5, 'days');

        $this->assertEquals('2023-01-06 00:00:00', $result);
    }

    public function testAddWeeks(): void
    {
        $result = ($this->tool)('2023-01-01', 2, 'weeks');

        $this->assertEquals('2023-01-15 00:00:00', $result);
    }

    public function testAddMonths(): void
    {
        $result = ($this->tool)('2023-01-15', 3, 'months');

        $this->assertEquals('2023-04-15 00:00:00', $result);
    }

    public function testAddYears(): void
    {
        $result = ($this->tool)('2020-02-29', 1, 'years'); // Leap year

        $this->assertEquals('2021-03-01 00:00:00', $result); // PHP's behavior when leap day doesn't exist
    }

    public function testAddWithTimestamp(): void
    {
        $timestamp = '1672531200'; // 2023-01-01 00:00:00 UTC
        $result = ($this->tool)($timestamp, 1, 'days');

        $this->assertEquals('2023-01-02 00:00:00', $result);
    }

    public function testAddWithTimezone(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 12, 'hours', 'America/New_York');

        $this->assertEquals('2023-01-02 00:00:00', $result);
    }

    public function testAddWithCustomFormat(): void
    {
        $result = ($this->tool)('2023-01-01', 1, 'days', null, 'Y/m/d');

        $this->assertEquals('2023/01/02', $result);
    }

    public function testAddFloatAmount(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 1.5, 'hours');

        $this->assertEquals('2023-01-01 13:30:00', $result);
    }

    public function testAddLargeAmount(): void
    {
        $result = ($this->tool)('2023-01-01', 365, 'days');

        $this->assertEquals('2024-01-01 00:00:00', $result);
    }

    public function testAddAcrossMonthBoundary(): void
    {
        $result = ($this->tool)('2023-01-28', 5, 'days');

        $this->assertEquals('2023-02-02 00:00:00', $result);
    }

    public function testAddAcrossYearBoundary(): void
    {
        $result = ($this->tool)('2023-12-30', 3, 'days');

        $this->assertEquals('2024-01-02 00:00:00', $result);
    }

    public function testAddToLeapYearFebruary(): void
    {
        $result = ($this->tool)('2024-02-28', 1, 'days'); // 2024 is leap year

        $this->assertEquals('2024-02-29 00:00:00', $result);
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
        $this->assertEquals('add_time', $this->tool->getName());
        $this->assertEquals('Add time periods to a date (supports days, weeks, months, years, hours, minutes, seconds)', $this->tool->getDescription());

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
