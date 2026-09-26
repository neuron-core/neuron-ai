<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\ConvertTimezoneTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;

class ConvertTimezoneToolTest extends TestCase
{
    protected ConvertTimezoneTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ConvertTimezoneTool();
    }

    public function test_convert_utc_to_new_york(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'America/New_York');

        $this->assertSame('2023-06-15 08:00:00 EDT', $result);
    }

    public function test_convert_utc_to_london(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Europe/London');

        $this->assertSame('2023-06-15 13:00:00 BST', $result);
    }

    public function test_convert_new_york_to_utc(): void
    {
        $result = ($this->tool)('2023-06-15 08:00:00', 'America/New_York', 'UTC');

        $this->assertSame('2023-06-15 12:00:00 UTC', $result);
    }

    public function test_convert_across_multiple_timezones(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:00', 'Europe/London', 'Asia/Tokyo');

        $this->assertSame('2023-06-15 22:30:00 JST', $result);
    }

    public function test_convert_with_timestamp(): void
    {
        $timestamp = '1686834000'; // 2023-06-15 13:00:00 UTC
        $result = ($this->tool)($timestamp, 'UTC', 'America/Los_Angeles');

        $this->assertSame('2023-06-15 06:00:00 PDT', $result);
    }

    public function test_convert_with_custom_format(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'America/New_York', 'Y-m-d H:i');

        $this->assertSame('2023-06-15 08:00', $result);
    }

    public function test_convert_with_iso_format(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Europe/Berlin', 'c');

        $this->assertSame('2023-06-15T14:00:00+02:00', $result);
    }

    public function test_convert_during_dst_transition(): void
    {
        // Test during EST (winter time)
        $winterResult = ($this->tool)('2023-01-15 12:00:00', 'UTC', 'America/New_York');
        $this->assertSame('2023-01-15 07:00:00 EST', $winterResult);

        // Test during EDT (summer time)
        $summerResult = ($this->tool)('2023-07-15 12:00:00', 'UTC', 'America/New_York');
        $this->assertSame('2023-07-15 08:00:00 EDT', $summerResult);
    }

    public function test_convert_same_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'UTC');

        $this->assertSame('2023-06-15 12:00:00 UTC', $result);
    }

    public function test_convert_with_seconds_and_microseconds(): void
    {
        $result = ($this->tool)('2023-06-15 12:30:45', 'UTC', 'America/Chicago', 'Y-m-d H:i:s T');

        $this->assertSame('2023-06-15 07:30:45 CDT', $result);
    }

    public function test_convert_negative_timezone_offset(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Pacific/Honolulu');

        $this->assertSame('2023-06-15 02:00:00 HST', $result);
    }

    public function test_convert_positive_timezone_offset(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Asia/Dubai');

        $this->assertSame('2023-06-15 16:00:00 +04', $result);
    }

    public function test_convert_across_dst_boundary(): void
    {
        // Convert from a timezone without DST to one with DST
        $result = ($this->tool)('2023-06-15 12:00:00', 'Asia/Dubai', 'Europe/Paris');

        $this->assertSame('2023-06-15 10:00:00 CEST', $result);
    }

    public function test_a_wall_time_inside_the_spring_forward_gap_moves_forward_an_hour(): void
    {
        // 02:30 does not exist in New York on 2024-03-10: it resolves to 03:30 EDT
        $this->assertSame('2024-03-10 07:30:00 UTC', ($this->tool)('2024-03-10 02:30:00', 'America/New_York', 'UTC'));
    }

    public function test_an_ambiguous_wall_time_in_the_fall_back_overlap_resolves_to_the_first_occurrence(): void
    {
        // 01:30 happens twice in New York on 2024-11-03: first in EDT, then in EST
        $this->assertSame('2024-11-03 05:30:00 UTC', ($this->tool)('2024-11-03 01:30:00', 'America/New_York', 'UTC'));
    }

    public function test_an_explicit_offset_in_the_date_wins_over_the_source_timezone(): void
    {
        $this->assertSame('2024-01-01 08:00:00 UTC', ($this->tool)('2024-01-01T10:00:00+02:00', 'America/New_York', 'UTC'));
    }

    public function test_converts_to_half_and_quarter_hour_offsets(): void
    {
        $this->assertSame('2023-01-15 17:30:00 IST', ($this->tool)('2023-01-15 12:00:00', 'UTC', 'Asia/Kolkata'));
        $this->assertSame('2023-01-15 17:45:00 +0545', ($this->tool)('2023-01-15 12:00:00', 'UTC', 'Asia/Kathmandu'));
        $this->assertSame('2023-01-15 08:30:00 NST', ($this->tool)('2023-01-15 12:00:00', 'UTC', 'America/St_Johns'));
    }

    public function test_accepts_a_fixed_utc_offset_as_timezone(): void
    {
        $this->assertSame('2023-01-15 17:30', ($this->tool)('2023-01-15 12:00:00', 'UTC', '+05:30', 'Y-m-d H:i'));
    }

    public function test_crosses_the_date_line(): void
    {
        $this->assertSame('2023-01-17 00:00:00', ($this->tool)('2023-01-15 23:00:00', 'Pacific/Pago_Pago', 'Pacific/Kiritimati', 'Y-m-d H:i:s'));
    }

    public function test_invalid_date(): void
    {
        $result = ($this->tool)('invalid-date', 'UTC', 'America/New_York');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_invalid_from_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'Invalid/Timezone', 'America/New_York');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_invalid_to_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 12:00:00', 'UTC', 'Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('convert_timezone', $this->tool->getName());
        $this->assertSame('Convert a date/time from one timezone to another', $this->tool->getDescription());

        $this->assertSame(
            ['date', 'from_timezone', 'to_timezone', 'format'],
            array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties())
        );
        $this->assertSame(['date', 'from_timezone', 'to_timezone'], $this->tool->getRequiredProperties());
    }
}
