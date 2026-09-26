<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\FormatDateTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;

class FormatDateToolTest extends TestCase
{
    protected FormatDateTool $tool;

    protected function setUp(): void
    {
        $this->tool = new FormatDateTool();
    }

    public function test_format_date_string_with_defaults(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45');

        $this->assertSame('2023-06-15 14:30:45', $result);
    }

    public function test_format_date_with_custom_format(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', 'Y/m/d H:i');

        $this->assertSame('2023/06/15 14:30', $result);
    }

    public function test_format_timestamp(): void
    {
        $timestamp = '1686831445'; // 2023-06-15 12:17:25 UTC
        $result = ($this->tool)($timestamp, 'Y-m-d H:i:s');

        $this->assertSame('2023-06-15 12:17:25', $result);
    }

    public function test_format_with_input_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', 'Y-m-d H:i:s T', 'America/New_York');

        $this->assertSame('2023-06-15 14:30:45 EDT', $result);
    }

    public function test_format_with_timezone_conversion(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', 'Y-m-d H:i:s T', 'UTC', 'America/New_York');

        $this->assertSame('2023-06-15 10:30:45 EDT', $result);
    }

    public function test_format_with_multiple_timezone_conversions(): void
    {
        // UTC to various timezones
        $utcToNy = ($this->tool)('2023-06-15 12:00:00', 'H:i', 'UTC', 'America/New_York');
        $utcToLondon = ($this->tool)('2023-06-15 12:00:00', 'H:i', 'UTC', 'Europe/London');
        $utcToTokyo = ($this->tool)('2023-06-15 12:00:00', 'H:i', 'UTC', 'Asia/Tokyo');

        $this->assertSame('08:00', $utcToNy); // UTC-4 in summer
        $this->assertSame('13:00', $utcToLondon); // UTC+1 in summer
        $this->assertSame('21:00', $utcToTokyo); // UTC+9
    }

    public function test_format_timestamp_with_timezone(): void
    {
        $timestamp = '1686834000'; // 2023-06-15 13:00:00 UTC
        $result = ($this->tool)($timestamp, 'Y-m-d H:i:s T', 'UTC', 'Europe/London');

        $this->assertSame('2023-06-15 14:00:00 BST', $result);
    }

    public function test_format_with_different_formats(): void
    {
        $date = '2023-12-25 09:15:30';

        $iso = ($this->tool)($date, 'c');
        $rfc = ($this->tool)($date, 'r');
        $custom = ($this->tool)($date, 'l, F jS Y \\a\\t g:i A');

        $this->assertSame('2023-12-25T09:15:30+00:00', $iso);
        $this->assertSame('Mon, 25 Dec 2023 09:15:30 +0000', $rfc);
        $this->assertSame('Monday, December 25th 2023 at 9:15 AM', $custom);
    }

    public function test_the_output_timezone_defaults_to_the_input_timezone(): void
    {
        $this->assertSame('2023-06-15 14:30:45 JST', ($this->tool)('2023-06-15 14:30:45', 'Y-m-d H:i:s T', 'Asia/Tokyo'));
    }

    public function test_a_timestamp_is_expressed_in_the_input_timezone(): void
    {
        $this->assertSame('2023-06-15 22:00:00 JST', ($this->tool)('1686834000', 'Y-m-d H:i:s T', 'Asia/Tokyo'));
    }

    public function test_an_explicit_offset_in_the_date_wins_over_the_input_timezone(): void
    {
        $this->assertSame('2023-06-15T14:30:45-03:00', ($this->tool)('2023-06-15T14:30:45-03:00', 'c', 'Asia/Tokyo'));
    }

    public function test_a_fall_back_wall_time_keeps_its_first_occurrence_offset(): void
    {
        $this->assertSame('2024-11-03 01:30:00 EDT', ($this->tool)('2024-11-03 01:30:00', 'Y-m-d H:i:s T', 'America/New_York'));
    }

    public function test_escaped_characters_and_multibyte_text_in_the_format_are_kept(): void
    {
        $this->assertSame('15 giugno ☕ 2023', ($this->tool)('2023-06-15', 'j \g\i\u\g\n\o ☕ Y'));
    }

    public function test_an_invalid_output_timezone_is_reported_as_an_error(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', null, 'UTC', 'Moon/Base');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Moon/Base', $result);
    }

    public function test_invalid_date(): void
    {
        $result = ($this->tool)('invalid-date');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', null, 'Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('format_date', $this->tool->getName());
        $this->assertSame('Format a date string or timestamp into different representations', $this->tool->getDescription());

        $this->assertSame(
            ['date', 'format', 'input_timezone', 'output_timezone'],
            array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties())
        );
        $this->assertSame(['date'], $this->tool->getRequiredProperties());
    }
}
