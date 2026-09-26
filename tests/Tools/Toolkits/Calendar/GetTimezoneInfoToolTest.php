<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetTimezoneInfoTool;
use NeuronAI\Tools\ToolPropertyInterface;
use DateTime;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;
use function time;

class GetTimezoneInfoToolTest extends TestCase
{
    protected GetTimezoneInfoTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GetTimezoneInfoTool();
    }

    public function test_get_utc_timezone_info(): void
    {
        $result = ($this->tool)('UTC');

        $data = json_decode($result, true);
        $this->assertSame('UTC', $data['timezone']);
        $this->assertSame(0, $data['offset_seconds']);
        $this->assertSame(0, $data['offset_hours']);
        $this->assertSame('+00:00', $data['offset_formatted']);
        $this->assertSame(false, $data['is_dst']);
        $this->assertSame('UTC', $data['abbreviation']);
    }

    public function test_get_new_york_timezone_info_summer(): void
    {
        $result = ($this->tool)('America/New_York', '2023-06-15 12:00:00');

        $data = json_decode($result, true);
        $this->assertSame('America/New_York', $data['timezone']);
        $this->assertSame(-14400, $data['offset_seconds']); // EDT is UTC-4
        $this->assertSame(-4, $data['offset_hours']);
        $this->assertSame('-04:00', $data['offset_formatted']);
        $this->assertSame('EDT', $data['abbreviation']);
        $this->assertArrayHasKey('location', $data);
    }

    public function test_get_new_york_timezone_info_winter(): void
    {
        $result = ($this->tool)('America/New_York', '2023-01-15 12:00:00');

        $data = json_decode($result, true);
        $this->assertSame('America/New_York', $data['timezone']);
        $this->assertSame(-18000, $data['offset_seconds']); // EST is UTC-5
        $this->assertSame(-5, $data['offset_hours']);
        $this->assertSame('-05:00', $data['offset_formatted']);
        $this->assertSame('EST', $data['abbreviation']);
    }

    public function test_get_london_timezone_info(): void
    {
        $result = ($this->tool)('Europe/London', '2023-06-15 12:00:00');

        $data = json_decode($result, true);
        $this->assertSame('Europe/London', $data['timezone']);
        $this->assertSame(3600, $data['offset_seconds']); // BST is UTC+1
        $this->assertSame(1, $data['offset_hours']);
        $this->assertSame('+01:00', $data['offset_formatted']);
        $this->assertSame('BST', $data['abbreviation']);
        $this->assertArrayHasKey('location', $data);
        $this->assertSame('GB', $data['location']['country_code']);
    }

    public function test_get_tokyo_timezone_info(): void
    {
        $result = ($this->tool)('Asia/Tokyo');

        $data = json_decode($result, true);
        $this->assertSame('Asia/Tokyo', $data['timezone']);
        $this->assertSame(32400, $data['offset_seconds']); // JST is UTC+9
        $this->assertSame(9, $data['offset_hours']);
        $this->assertSame('+09:00', $data['offset_formatted']);
        $this->assertSame('JST', $data['abbreviation']);
        $this->assertArrayHasKey('location', $data);
        $this->assertSame('JP', $data['location']['country_code']);
    }

    public function test_get_timezone_info_with_timestamp(): void
    {
        $timestamp = '1686834000'; // 2023-06-15 13:00:00 UTC
        $result = ($this->tool)('Europe/Berlin', $timestamp);

        $data = json_decode($result, true);
        $this->assertSame('Europe/Berlin', $data['timezone']);
        $this->assertSame(7200, $data['offset_seconds']); // CEST is UTC+2
        $this->assertSame('CEST', $data['abbreviation']);
    }

    public function test_without_a_reference_date_the_current_instant_is_described(): void
    {
        $before = time();
        $data = json_decode(($this->tool)('America/Chicago'), true);
        $after = time();

        $reference = DateTime::createFromFormat('Y-m-d H:i:s T', $data['reference_time']);
        $this->assertInstanceOf(DateTime::class, $reference);
        $this->assertGreaterThanOrEqual($before, $reference->getTimestamp());
        $this->assertLessThanOrEqual($after, $reference->getTimestamp());
        $this->assertSame($reference->getOffset(), $data['offset_seconds']);
        $this->assertSame($data['offset_seconds'] === -18000 ? 'CDT' : 'CST', $data['abbreviation']);
        $this->assertSame($data['offset_seconds'] === -18000, $data['is_dst']);
    }

    public function test_a_named_zone_reports_its_location(): void
    {
        $result = json_decode(($this->tool)('Australia/Sydney', '2023-01-15 12:00:00'), true);

        $this->assertSame(['country_code' => 'AU', 'latitude' => -33.86666, 'longitude' => 151.21666], $result['location']);
    }

    public function test_get_timezone_info_without_location(): void
    {
        $result = ($this->tool)('UTC');

        $data = json_decode($result, true);
        $this->assertSame('UTC', $data['timezone']);
        $this->assertNull($data['location']);
    }

    public function test_describes_every_field_for_a_reference_date(): void
    {
        $this->assertSame([
            'timezone' => 'Europe/Paris',
            'offset_seconds' => 7200,
            'offset_hours' => 2,
            'offset_formatted' => '+02:00',
            'is_dst' => true,
            'abbreviation' => 'CEST',
            'location' => ['country_code' => 'FR', 'latitude' => 48.86666, 'longitude' => 2.33333],
            'reference_time' => '2023-07-01 12:00:00 CEST',
        ], json_decode(($this->tool)('Europe/Paris', '2023-07-01 12:00:00'), true));
    }

    public function test_is_dst_follows_the_reference_date(): void
    {
        $summer = json_decode(($this->tool)('America/New_York', '2023-07-15 12:00:00'), true);
        $winter = json_decode(($this->tool)('America/New_York', '2023-01-15 12:00:00'), true);

        $this->assertTrue($summer['is_dst']);
        $this->assertFalse($winter['is_dst']);
    }

    public function test_is_dst_in_the_southern_hemisphere_summer(): void
    {
        $result = json_decode(($this->tool)('Australia/Sydney', '2023-01-15 12:00:00'), true);

        $this->assertSame([39600, true, 'AEDT'], [$result['offset_seconds'], $result['is_dst'], $result['abbreviation']]);
    }

    public function test_describes_a_half_hour_offset(): void
    {
        $this->assertSame([
            'timezone' => 'Asia/Kolkata',
            'offset_seconds' => 19800,
            'offset_hours' => 5.5,
            'offset_formatted' => '+05:30',
            'is_dst' => false,
            'abbreviation' => 'IST',
            'location' => ['country_code' => 'IN', 'latitude' => 22.53333, 'longitude' => 88.36666],
            'reference_time' => '2023-01-15 12:00:00 IST',
        ], json_decode(($this->tool)('Asia/Kolkata', '2023-01-15 12:00:00'), true));
    }

    public function test_a_fixed_offset_timezone_has_no_location(): void
    {
        $result = json_decode(($this->tool)('+05:30', '2023-01-15 12:00:00'), true);

        $this->assertSame(['+05:30', 19800, null], [$result['offset_formatted'], $result['offset_seconds'], $result['location']]);
    }

    public function test_the_reference_time_is_interpreted_in_the_requested_timezone(): void
    {
        $result = json_decode(($this->tool)('Asia/Tokyo', '2023-06-15 09:00:00'), true);

        $this->assertSame('2023-06-15 09:00:00 JST', $result['reference_time']);
    }

    public function test_an_invalid_timezone_error_names_the_offending_value(): void
    {
        $result = ($this->tool)('Europe/Atlantis');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Europe/Atlantis', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_invalid_reference_date(): void
    {
        $result = ($this->tool)('UTC', 'invalid-date');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('get_timezone_info', $this->tool->getName());
        $this->assertSame('Get detailed information about a timezone including offset and DST rules', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('timezone', $propertyNames);
        $this->assertContains('reference_date', $propertyNames);
    }
}
