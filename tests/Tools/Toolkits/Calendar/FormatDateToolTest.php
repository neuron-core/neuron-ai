<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\FormatDateTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;

class FormatDateToolTest extends TestCase
{
    private FormatDateTool $tool;

    protected function setUp(): void
    {
        $this->tool = new FormatDateTool();
    }

    public function test_format_date_string_with_defaults(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45');

        $this->assertEquals('2023-06-15 14:30:45', $result);
    }

    public function test_format_date_with_custom_format(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', 'Y/m/d H:i');

        $this->assertEquals('2023/06/15 14:30', $result);
    }

    public function test_format_timestamp(): void
    {
        $timestamp = '1686831445'; // 2023-06-15 13:37:25 UTC
        $result = ($this->tool)($timestamp, 'Y-m-d H:i:s');

        $this->assertEquals('2023-06-15 12:17:25', $result);
    }

    public function test_format_with_input_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', 'Y-m-d H:i:s T', 'America/New_York');

        $this->assertEquals('2023-06-15 14:30:45 EDT', $result);
    }

    public function test_format_with_timezone_conversion(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', 'Y-m-d H:i:s T', 'UTC', 'America/New_York');

        $this->assertEquals('2023-06-15 10:30:45 EDT', $result);
    }

    public function test_format_with_multiple_timezone_conversions(): void
    {
        // UTC to various timezones
        $utcToNy = ($this->tool)('2023-06-15 12:00:00', 'H:i', 'UTC', 'America/New_York');
        $utcToLondon = ($this->tool)('2023-06-15 12:00:00', 'H:i', 'UTC', 'Europe/London');
        $utcToTokyo = ($this->tool)('2023-06-15 12:00:00', 'H:i', 'UTC', 'Asia/Tokyo');

        $this->assertEquals('08:00', $utcToNy); // UTC-4 in summer
        $this->assertEquals('13:00', $utcToLondon); // UTC+1 in summer
        $this->assertEquals('21:00', $utcToTokyo); // UTC+9
    }

    public function test_format_timestamp_with_timezone(): void
    {
        $timestamp = '1686834000'; // 2023-06-15 14:00:00 UTC
        $result = ($this->tool)($timestamp, 'Y-m-d H:i:s T', 'UTC', 'Europe/London');

        $this->assertEquals('2023-06-15 14:00:00 BST', $result);
    }

    public function test_format_with_different_formats(): void
    {
        $date = '2023-12-25 09:15:30';

        $iso = ($this->tool)($date, 'c');
        $rfc = ($this->tool)($date, 'r');
        $custom = ($this->tool)($date, 'l, F jS Y \\a\\t g:i A');

        $this->assertEquals('2023-12-25T09:15:30+00:00', $iso);
        $this->assertEquals('Mon, 25 Dec 2023 09:15:30 +0000', $rfc);
        $this->assertEquals('Monday, December 25th 2023 at 9:15 AM', $custom);
    }

    public function test_invalid_date(): void
    {
        $result = ($this->tool)('invalid-date');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('2023-06-15 14:30:45', null, 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertEquals('format_date', $this->tool->getName());
        $this->assertEquals('Format a date string or timestamp into different representations', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(4, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('date', $propertyNames);
        $this->assertContains('format', $propertyNames);
        $this->assertContains('input_timezone', $propertyNames);
        $this->assertContains('output_timezone', $propertyNames);
    }
}
