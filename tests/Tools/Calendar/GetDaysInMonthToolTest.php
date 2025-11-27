<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetDaysInMonthTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class GetDaysInMonthToolTest extends TestCase
{
    private GetDaysInMonthTool $tool;

    protected function setUp(): void
    {
        $this->tool = new GetDaysInMonthTool();
    }

    public function testJanuary(): void
    {
        $result = ($this->tool)(1, 2023);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(1, $data['month']);
        $this->assertEquals('January', $data['month_name']);
        $this->assertEquals(2023, $data['year']);
        $this->assertEquals(31, $data['days_in_month']);
        $this->assertFalse($data['is_leap_year']);
        $this->assertEquals('2023-01-01', $data['first_day']);
        $this->assertEquals('2023-01-31', $data['last_day']);
    }

    public function testFebruaryNonLeapYear(): void
    {
        $result = ($this->tool)(2, 2023);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2, $data['month']);
        $this->assertEquals('February', $data['month_name']);
        $this->assertEquals(2023, $data['year']);
        $this->assertEquals(28, $data['days_in_month']);
        $this->assertFalse($data['is_leap_year']);
        $this->assertEquals('2023-02-01', $data['first_day']);
        $this->assertEquals('2023-02-28', $data['last_day']);
    }

    public function testFebruaryLeapYear(): void
    {
        $result = ($this->tool)(2, 2024);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2, $data['month']);
        $this->assertEquals('February', $data['month_name']);
        $this->assertEquals(2024, $data['year']);
        $this->assertEquals(29, $data['days_in_month']);
        $this->assertTrue($data['is_leap_year']);
        $this->assertEquals('2024-02-01', $data['first_day']);
        $this->assertEquals('2024-02-29', $data['last_day']);
    }

    public function testMarch(): void
    {
        $result = ($this->tool)(3, 2023);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(3, $data['month']);
        $this->assertEquals('March', $data['month_name']);
        $this->assertEquals(2023, $data['year']);
        $this->assertEquals(31, $data['days_in_month']);
        $this->assertEquals('2023-03-01', $data['first_day']);
        $this->assertEquals('2023-03-31', $data['last_day']);
    }

    public function testApril(): void
    {
        $result = ($this->tool)(4, 2023);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(4, $data['month']);
        $this->assertEquals('April', $data['month_name']);
        $this->assertEquals(2023, $data['year']);
        $this->assertEquals(30, $data['days_in_month']);
        $this->assertEquals('2023-04-01', $data['first_day']);
        $this->assertEquals('2023-04-30', $data['last_day']);
    }

    public function testAllMonthsNonLeapYear(): void
    {
        $expectedDays = [
            1 => [31, 'January'],
            2 => [28, 'February'],
            3 => [31, 'March'],
            4 => [30, 'April'],
            5 => [31, 'May'],
            6 => [30, 'June'],
            7 => [31, 'July'],
            8 => [31, 'August'],
            9 => [30, 'September'],
            10 => [31, 'October'],
            11 => [30, 'November'],
            12 => [31, 'December'],
        ];

        foreach ($expectedDays as $month => [$expectedDaysCount, $expectedName]) {
            $result = ($this->tool)($month, 2023);
            $data = json_decode($result, true);

            $this->assertEquals($expectedDaysCount, $data['days_in_month'], "Failed for month $month");
            $this->assertEquals($expectedName, $data['month_name'], "Failed for month $month");
            $this->assertFalse($data['is_leap_year'], "Failed for month $month");
        }
    }

    public function testAllMonthsLeapYear(): void
    {
        $expectedDays = [
            1 => [31, 'January'],
            2 => [29, 'February'], // Leap year
            3 => [31, 'March'],
            4 => [30, 'April'],
            5 => [31, 'May'],
            6 => [30, 'June'],
            7 => [31, 'July'],
            8 => [31, 'August'],
            9 => [30, 'September'],
            10 => [31, 'October'],
            11 => [30, 'November'],
            12 => [31, 'December'],
        ];

        foreach ($expectedDays as $month => [$expectedDaysCount, $expectedName]) {
            $result = ($this->tool)($month, 2024);
            $data = json_decode($result, true);

            $this->assertEquals($expectedDaysCount, $data['days_in_month'], "Failed for month $month in leap year");
            $this->assertEquals($expectedName, $data['month_name'], "Failed for month $month in leap year");
            $this->assertTrue($data['is_leap_year'], "Failed for month $month in leap year");
        }
    }

    public function testDecember(): void
    {
        $result = ($this->tool)(12, 2023);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(12, $data['month']);
        $this->assertEquals('December', $data['month_name']);
        $this->assertEquals(2023, $data['year']);
        $this->assertEquals(31, $data['days_in_month']);
        $this->assertEquals('2023-12-01', $data['first_day']);
        $this->assertEquals('2023-12-31', $data['last_day']);
    }

    public function testFebruaryCenturyYear(): void
    {
        // Test century years (1900 is not leap, 2000 is leap)
        $result1900 = ($this->tool)(2, 1900);
        $result2000 = ($this->tool)(2, 2000);

        $data1900 = json_decode($result1900, true);
        $data2000 = json_decode($result2000, true);

        $this->assertEquals(28, $data1900['days_in_month']);
        $this->assertFalse($data1900['is_leap_year']);

        $this->assertEquals(29, $data2000['days_in_month']);
        $this->assertTrue($data2000['is_leap_year']);
    }

    public function testFirstAndLastDayFormatting(): void
    {
        // Test single digit months get zero-padded
        $result = ($this->tool)(5, 2023);
        $data = json_decode($result, true);

        $this->assertEquals('2023-05-01', $data['first_day']);
        $this->assertEquals('2023-05-31', $data['last_day']);
    }

    public function testJsonStructure(): void
    {
        $result = ($this->tool)(6, 2023);

        $data = json_decode($result, true);
        $this->assertIsArray($data);

        // Check all required keys are present
        $expectedKeys = ['month', 'month_name', 'year', 'days_in_month', 'is_leap_year', 'first_day', 'last_day'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        // Check data types
        $this->assertIsInt($data['month']);
        $this->assertIsString($data['month_name']);
        $this->assertIsInt($data['year']);
        $this->assertIsInt($data['days_in_month']);
        $this->assertIsBool($data['is_leap_year']);
        $this->assertIsString($data['first_day']);
        $this->assertIsString($data['last_day']);
    }

    public function testInvalidMonthLow(): void
    {
        $result = ($this->tool)(0, 2023);

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testInvalidMonthHigh(): void
    {
        $result = ($this->tool)(13, 2023);

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testNegativeMonth(): void
    {
        $result = ($this->tool)(-1, 2023);

        $this->assertStringStartsWith('Error:', $result);
    }

    public function testHistoricalYear(): void
    {
        $result = ($this->tool)(7, 1776);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(7, $data['month']);
        $this->assertEquals('July', $data['month_name']);
        $this->assertEquals(1776, $data['year']);
        $this->assertEquals(31, $data['days_in_month']);
        $this->assertTrue($data['is_leap_year']); // 1776 is divisible by 4
    }

    public function testFutureYear(): void
    {
        $result = ($this->tool)(2, 2100);

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertEquals(2, $data['month']);
        $this->assertEquals('February', $data['month_name']);
        $this->assertEquals(2100, $data['year']);
        $this->assertEquals(28, $data['days_in_month']); // 2100 is not a leap year
        $this->assertFalse($data['is_leap_year']);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('get_days_in_month', $this->tool->getName());
        $this->assertEquals('Get the number of days in a specific month and year', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('month', $propertyNames);
        $this->assertContains('year', $propertyNames);
    }
}
