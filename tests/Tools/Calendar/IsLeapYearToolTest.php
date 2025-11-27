<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\IsLeapYearTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class IsLeapYearToolTest extends TestCase
{
    private IsLeapYearTool $tool;

    protected function setUp(): void
    {
        $this->tool = new IsLeapYearTool();
    }

    public function testLeapYear2020(): void
    {
        $result = ($this->tool)(2020);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2020, $data['year']);
        $this->assertTrue($data['is_leap_year']);
        $this->assertEquals(366, $data['days_in_year']);
        $this->assertEquals(29, $data['february_days']);
    }

    public function testLeapYear2024(): void
    {
        $result = ($this->tool)(2024);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2024, $data['year']);
        $this->assertTrue($data['is_leap_year']);
        $this->assertEquals(366, $data['days_in_year']);
        $this->assertEquals(29, $data['february_days']);
    }

    public function testNonLeapYear2021(): void
    {
        $result = ($this->tool)(2021);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2021, $data['year']);
        $this->assertFalse($data['is_leap_year']);
        $this->assertEquals(365, $data['days_in_year']);
        $this->assertEquals(28, $data['february_days']);
    }

    public function testNonLeapYear2022(): void
    {
        $result = ($this->tool)(2022);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2022, $data['year']);
        $this->assertFalse($data['is_leap_year']);
        $this->assertEquals(365, $data['days_in_year']);
        $this->assertEquals(28, $data['february_days']);
    }

    public function testNonLeapYear2023(): void
    {
        $result = ($this->tool)(2023);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2023, $data['year']);
        $this->assertFalse($data['is_leap_year']);
        $this->assertEquals(365, $data['days_in_year']);
        $this->assertEquals(28, $data['february_days']);
    }

    public function testCenturyYearNotLeap1900(): void
    {
        // 1900 is divisible by 4 and 100, but not by 400, so it's not a leap year
        $result = ($this->tool)(1900);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(1900, $data['year']);
        $this->assertFalse($data['is_leap_year']);
        $this->assertEquals(365, $data['days_in_year']);
        $this->assertEquals(28, $data['february_days']);
    }

    public function testCenturyYearNotLeap1800(): void
    {
        // 1800 is divisible by 4 and 100, but not by 400, so it's not a leap year
        $result = ($this->tool)(1800);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(1800, $data['year']);
        $this->assertFalse($data['is_leap_year']);
        $this->assertEquals(365, $data['days_in_year']);
        $this->assertEquals(28, $data['february_days']);
    }

    public function testCenturyYearLeap2000(): void
    {
        // 2000 is divisible by 400, so it's a leap year
        $result = ($this->tool)(2000);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2000, $data['year']);
        $this->assertTrue($data['is_leap_year']);
        $this->assertEquals(366, $data['days_in_year']);
        $this->assertEquals(29, $data['february_days']);
    }

    public function testCenturyYearLeap1600(): void
    {
        // 1600 is divisible by 400, so it's a leap year
        $result = ($this->tool)(1600);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(1600, $data['year']);
        $this->assertTrue($data['is_leap_year']);
        $this->assertEquals(366, $data['days_in_year']);
        $this->assertEquals(29, $data['february_days']);
    }

    public function testFutureLeapYear2028(): void
    {
        $result = ($this->tool)(2028);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2028, $data['year']);
        $this->assertTrue($data['is_leap_year']);
        $this->assertEquals(366, $data['days_in_year']);
        $this->assertEquals(29, $data['february_days']);
    }

    public function testHistoricalLeapYear1996(): void
    {
        $result = ($this->tool)(1996);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(1996, $data['year']);
        $this->assertTrue($data['is_leap_year']);
        $this->assertEquals(366, $data['days_in_year']);
        $this->assertEquals(29, $data['february_days']);
    }

    public function testMultipleYears(): void
    {
        $testCases = [
            2016 => true,  // Leap year
            2017 => false, // Not leap year
            2018 => false, // Not leap year
            2019 => false, // Not leap year
            2020 => true,  // Leap year
            2100 => false, // Not leap year (century year not divisible by 400)
            2400 => true,  // Leap year (divisible by 400)
        ];

        foreach ($testCases as $year => $expectedLeap) {
            $result = ($this->tool)($year);
            $data = json_decode($result, true);

            $this->assertEquals($expectedLeap, $data['is_leap_year'], "Failed for year $year");
            $this->assertEquals($expectedLeap ? 366 : 365, $data['days_in_year'], "Failed for year $year");
            $this->assertEquals($expectedLeap ? 29 : 28, $data['february_days'], "Failed for year $year");
        }
    }

    public function testJsonStructure(): void
    {
        $result = ($this->tool)(2020);

        $data = json_decode($result, true);
        $this->assertIsArray($data);

        // Check all required keys are present
        $expectedKeys = ['year', 'is_leap_year', 'days_in_year', 'february_days'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        // Check data types
        $this->assertIsInt($data['year']);
        $this->assertIsBool($data['is_leap_year']);
        $this->assertIsInt($data['days_in_year']);
        $this->assertIsInt($data['february_days']);
    }

    public function testLeapYearLogic(): void
    {
        // Test the leap year algorithm directly
        $leapYears = [1600, 2000, 2004, 2008, 2012, 2016, 2020, 2024, 2400];
        $nonLeapYears = [1700, 1800, 1900, 2001, 2002, 2003, 2100, 2200, 2300];

        foreach ($leapYears as $year) {
            $result = ($this->tool)($year);
            $data = json_decode($result, true);
            $this->assertTrue($data['is_leap_year'], "Year $year should be a leap year");
        }

        foreach ($nonLeapYears as $year) {
            $result = ($this->tool)($year);
            $data = json_decode($result, true);
            $this->assertFalse($data['is_leap_year'], "Year $year should not be a leap year");
        }
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('is_leap_year', $this->tool->getName());
        $this->assertEquals('Check if a given year is a leap year', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(1, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('year', $propertyNames);
    }
}
