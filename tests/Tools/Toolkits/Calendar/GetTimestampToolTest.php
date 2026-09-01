<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetTimestampTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function time;

class GetTimestampToolTest extends TestCase
{
    private GetTimestampTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GetTimestampTool();
    }

    public function test_get_current_timestamp(): void
    {
        $before = time();
        $result = ($this->tool)();
        $after = time();

        $timestamp = (int) $result;
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function test_convert_date_to_timestamp(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00');

        $this->assertEquals('1672574400', $result); // 2023-01-01 12:00:00 UTC
    }

    public function test_convert_date_with_timezone(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 'America/New_York');

        $this->assertEquals('1672592400', $result); // 2023-01-01 12:00:00 EST (UTC-5)
    }

    public function test_convert_date_with_different_timezones(): void
    {
        $utcResult = ($this->tool)('2023-06-01 12:00:00', 'UTC');
        $nyResult = ($this->tool)('2023-06-01 12:00:00', 'America/New_York');
        $londonResult = ($this->tool)('2023-06-01 12:00:00', 'Europe/London');

        // New York is 4 hours behind UTC in summer (EDT)
        $this->assertEquals((int) $utcResult + 14400, (int) $nyResult);

        // London is 1 hour ahead of UTC in summer (BST)
        $this->assertEquals((int) $utcResult - 3600, (int) $londonResult);
    }

    public function test_invalid_date(): void
    {
        $result = ($this->tool)('invalid-date');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertEquals('get_timestamp', $this->tool->getName());
        $this->assertEquals('Get Unix timestamp for current time or convert a specific date to timestamp', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('date', $propertyNames);
        $this->assertContains('timezone', $propertyNames);
    }
}
