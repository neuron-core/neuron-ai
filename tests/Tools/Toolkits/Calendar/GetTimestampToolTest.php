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
    protected GetTimestampTool $tool;

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

        $this->assertSame('1672574400', $result); // 2023-01-01 12:00:00 UTC
    }

    public function test_convert_date_with_timezone(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 'America/New_York');

        $this->assertSame('1672592400', $result); // 2023-01-01 12:00:00 EST (UTC-5)
    }

    public function test_convert_date_with_different_timezones(): void
    {
        $utcResult = ($this->tool)('2023-06-01 12:00:00', 'UTC');
        $nyResult = ($this->tool)('2023-06-01 12:00:00', 'America/New_York');
        $londonResult = ($this->tool)('2023-06-01 12:00:00', 'Europe/London');

        // New York is 4 hours behind UTC in summer (EDT)
        $this->assertSame((int) $utcResult + 14400, (int) $nyResult);

        // London is 1 hour ahead of UTC in summer (BST)
        $this->assertSame((int) $utcResult - 3600, (int) $londonResult);
    }

    public function test_an_at_prefixed_timestamp_round_trips(): void
    {
        $this->assertSame('1700000000', ($this->tool)('@1700000000'));
        $this->assertSame('1700000000', ($this->tool)('@1700000000', 'Asia/Tokyo'));
    }

    public function test_an_explicit_offset_in_the_date_wins_over_the_timezone(): void
    {
        $this->assertSame('1672567200', ($this->tool)('2023-01-01T12:00:00+02:00', 'America/New_York'));
    }

    public function test_the_fall_back_overlap_resolves_to_the_first_occurrence(): void
    {
        // 01:30 EDT on 2024-11-03 is 05:30 UTC; the later 01:30 EST would be 06:30 UTC
        $this->assertSame('1730611800', ($this->tool)('2024-11-03 01:30:00', 'America/New_York'));
    }

    public function test_dates_before_the_epoch_are_negative(): void
    {
        $this->assertSame('-86400', ($this->tool)('1969-12-31 00:00:00'));
    }

    public function test_an_invalid_date_is_reported_as_an_error_naming_it(): void
    {
        $result = ($this->tool)('invalid-date');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_an_invalid_timezone_is_reported_as_an_error_naming_it(): void
    {
        $result = ($this->tool)('2023-01-01 12:00:00', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('get_timestamp', $this->tool->getName());
        $this->assertSame('Get Unix timestamp for current time or convert a specific date to timestamp', $this->tool->getDescription());

        $this->assertSame(
            ['date', 'timezone'],
            array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties())
        );
        $this->assertSame([], $this->tool->getRequiredProperties());
    }
}
