<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\CalculateAgeTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function date;
use function json_decode;

class CalculateAgeToolTest extends TestCase
{
    private CalculateAgeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new CalculateAgeTool();
    }

    public function test_calculate_age_in_years(): void
    {
        $result = ($this->tool)('1990-01-01', '2023-01-01');

        $this->assertEquals('33', $result);
    }

    public function test_calculate_age_in_years_before_birthday(): void
    {
        $result = ($this->tool)('1990-06-15', '2023-01-01');

        $this->assertEquals('32', $result);
    }

    public function test_calculate_age_in_years_after_birthday(): void
    {
        $result = ($this->tool)('1990-01-01', '2023-06-15');

        $this->assertEquals('33', $result);
    }

    public function test_calculate_age_in_months(): void
    {
        $result = ($this->tool)('2022-01-01', '2023-07-01', 'months');

        $this->assertEquals('18', $result);
    }

    public function test_calculate_age_in_days(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-10', 'days');

        $this->assertEquals('9', $result);
    }

    public function test_calculate_age_all(): void
    {
        $result = ($this->tool)('1990-03-15', '2023-07-20', 'all');

        $data = json_decode($result, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('years', $data);
        $this->assertArrayHasKey('months', $data);
        $this->assertArrayHasKey('days', $data);
        $this->assertArrayHasKey('total_days', $data);
        $this->assertArrayHasKey('next_birthday', $data);

        $this->assertEquals(33, $data['years']);
        $this->assertEquals(4, $data['months']);
        $this->assertIsInt($data['total_days']);
        $this->assertStringContainsString('2024-03-15', $data['next_birthday']);
    }

    public function test_calculate_age_with_current_date(): void
    {
        $birthdate = '2020-01-01';
        $result = ($this->tool)($birthdate); // No reference date = current date

        $age = (int) $result;
        $currentYear = (int) date('Y');
        $expectedAge = $currentYear - 2020;

        // Age should be either expectedAge or expectedAge-1 depending on if birthday has passed
        $this->assertTrue($age === $expectedAge || $age === $expectedAge - 1);
    }

    public function test_calculate_age_with_timestamp(): void
    {
        $birthTimestamp = '631152000'; // 1990-01-01 00:00:00 UTC
        $refTimestamp = '1672531200';  // 2023-01-01 00:00:00 UTC

        $result = ($this->tool)($birthTimestamp, $refTimestamp);

        $this->assertEquals('33', $result);
    }

    public function test_calculate_age_with_timezone(): void
    {
        $result = ($this->tool)('1990-01-01 12:00:00', '2023-01-01 06:00:00', null, 'America/New_York');

        $this->assertEquals('32', $result); // Should be 32 because 6 AM EST is before 12 PM EST on same date
    }

    public function test_calculate_age_leap_year(): void
    {
        // Born on leap day
        $result = ($this->tool)('2020-02-29', '2023-02-28');

        $this->assertEquals('2', $result); // Should be 2 years (birthday not reached yet)
    }

    public function test_calculate_age_exact_birthday(): void
    {
        $result = ($this->tool)('1990-06-15', '2023-06-15');

        $this->assertEquals('33', $result);
    }

    public function test_calculate_age_future_birthdate(): void
    {
        $result = ($this->tool)('2025-01-01', '2023-01-01');

        $this->assertEquals('2', $result); // Should handle negative age as positive
    }

    public function test_calculate_age_same_date(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-01');

        $this->assertEquals('0', $result);
    }

    public function test_invalid_birthdate(): void
    {
        $result = ($this->tool)('invalid-date', '2023-01-01');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_invalid_reference_date(): void
    {
        $result = ($this->tool)('1990-01-01', 'invalid-date');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('1990-01-01', '2023-01-01', null, 'Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertEquals('calculate_age', $this->tool->getName());
        $this->assertEquals('Calculate age in years, months, and days from birthdate to a reference date', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(4, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('birthdate', $propertyNames);
        $this->assertContains('reference_date', $propertyNames);
        $this->assertContains('unit', $propertyNames);
        $this->assertContains('timezone', $propertyNames);
    }
}
