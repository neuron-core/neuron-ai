<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetTimezoneInfoTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class GetTimezoneInfoToolTest extends TestCase
{
    private GetTimezoneInfoTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GetTimezoneInfoTool();
    }

    public function testGetUtcTimezoneInfo(): void
    {
        $result = ($this->tool)('UTC');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('UTC', $data['timezone']);
        $this->assertEquals(0, $data['offset_seconds']);
        $this->assertEquals(0.0, $data['offset_hours']);
        $this->assertEquals('+00:00', $data['offset_formatted']);
        $this->assertEquals(false, $data['is_dst']);
        $this->assertEquals('UTC', $data['abbreviation']);
    }

    public function testGetNewYorkTimezoneInfoSummer(): void
    {
        $result = ($this->tool)('America/New_York', '2023-06-15 12:00:00');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('America/New_York', $data['timezone']);
        $this->assertEquals(-14400, $data['offset_seconds']); // EDT is UTC-4
        $this->assertEquals(-4.0, $data['offset_hours']);
        $this->assertEquals('-04:00', $data['offset_formatted']);
        $this->assertEquals('EDT', $data['abbreviation']);
        $this->assertArrayHasKey('location', $data);
    }

    public function testGetNewYorkTimezoneInfoWinter(): void
    {
        $result = ($this->tool)('America/New_York', '2023-01-15 12:00:00');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('America/New_York', $data['timezone']);
        $this->assertEquals(-18000, $data['offset_seconds']); // EST is UTC-5
        $this->assertEquals(-5.0, $data['offset_hours']);
        $this->assertEquals('-05:00', $data['offset_formatted']);
        $this->assertEquals('EST', $data['abbreviation']);
    }

    public function testGetLondonTimezoneInfo(): void
    {
        $result = ($this->tool)('Europe/London', '2023-06-15 12:00:00');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('Europe/London', $data['timezone']);
        $this->assertEquals(3600, $data['offset_seconds']); // BST is UTC+1
        $this->assertEquals(1.0, $data['offset_hours']);
        $this->assertEquals('+01:00', $data['offset_formatted']);
        $this->assertEquals('BST', $data['abbreviation']);
        $this->assertArrayHasKey('location', $data);
        $this->assertEquals('GB', $data['location']['country_code']);
    }

    public function testGetTokyoTimezoneInfo(): void
    {
        $result = ($this->tool)('Asia/Tokyo');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('Asia/Tokyo', $data['timezone']);
        $this->assertEquals(32400, $data['offset_seconds']); // JST is UTC+9
        $this->assertEquals(9.0, $data['offset_hours']);
        $this->assertEquals('+09:00', $data['offset_formatted']);
        $this->assertEquals('JST', $data['abbreviation']);
        $this->assertArrayHasKey('location', $data);
        $this->assertEquals('JP', $data['location']['country_code']);
    }

    public function testGetTimezoneInfoWithTimestamp(): void
    {
        $timestamp = '1686834000'; // 2023-06-15 14:00:00 UTC
        $result = ($this->tool)('Europe/Berlin', $timestamp);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('Europe/Berlin', $data['timezone']);
        $this->assertEquals(7200, $data['offset_seconds']); // CEST is UTC+2
        $this->assertEquals('CEST', $data['abbreviation']);
    }

    public function testGetTimezoneInfoCurrentTime(): void
    {
        $result = ($this->tool)('America/Chicago');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('America/Chicago', $data['timezone']);
        $this->assertArrayHasKey('offset_seconds', $data);
        $this->assertArrayHasKey('offset_hours', $data);
        $this->assertArrayHasKey('offset_formatted', $data);
        $this->assertArrayHasKey('is_dst', $data);
        $this->assertArrayHasKey('abbreviation', $data);
        $this->assertArrayHasKey('reference_time', $data);

        // Chicago is either CST (UTC-6) or CDT (UTC-5)
        $this->assertTrue($data['offset_seconds'] === -21600 || $data['offset_seconds'] === -18000);
    }

    public function testGetTimezoneInfoWithLocation(): void
    {
        $result = ($this->tool)('Australia/Sydney');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('Australia/Sydney', $data['timezone']);
        $this->assertArrayHasKey('location', $data);
        $this->assertNotNull($data['location']);
        $this->assertEquals('AU', $data['location']['country_code']);
        $this->assertArrayHasKey('latitude', $data['location']);
        $this->assertArrayHasKey('longitude', $data['location']);
    }

    public function testGetTimezoneInfoWithoutLocation(): void
    {
        $result = ($this->tool)('UTC');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals('UTC', $data['timezone']);
        $this->assertNull($data['location']);
    }

    public function testGetTimezoneInfoAllFields(): void
    {
        $result = ($this->tool)('Europe/Paris', '2023-07-01 12:00:00');

        $data = json_decode($result, true);
        $this->assertIsArray($data);

        // Check all expected fields are present
        $expectedFields = [
            'timezone',
            'offset_seconds',
            'offset_hours',
            'offset_formatted',
            'is_dst',
            'abbreviation',
            'location',
            'reference_time'
        ];

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $data);
        }

        $this->assertEquals('Europe/Paris', $data['timezone']);
        $this->assertEquals(7200, $data['offset_seconds']); // CEST is UTC+2
        $this->assertEquals(2.0, $data['offset_hours']);
        $this->assertEquals('+02:00', $data['offset_formatted']);
        $this->assertEquals('CEST', $data['abbreviation']);
        $this->assertStringStartsWith('2023-07-01 12:00:00', $data['reference_time']);
    }

    public function testInvalidTimezone(): void
    {
        $result = ($this->tool)('Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testInvalidReferenceDate(): void
    {
        $result = ($this->tool)('UTC', 'invalid-date');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('get_timezone_info', $this->tool->getName());
        $this->assertEquals('Get detailed information about a timezone including offset and DST rules', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('timezone', $propertyNames);
        $this->assertContains('reference_date', $propertyNames);
    }
}
