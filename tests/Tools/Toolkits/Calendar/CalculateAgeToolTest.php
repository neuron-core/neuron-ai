<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Tools\Toolkits\Calendar\CalculateAgeTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function json_decode;

class CalculateAgeToolTest extends TestCase
{
    protected CalculateAgeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new CalculateAgeTool();
    }

    public function test_calculate_age_in_years(): void
    {
        $result = ($this->tool)('1990-01-01', '2023-01-01');

        $this->assertSame('33', $result);
    }

    public function test_calculate_age_in_years_before_birthday(): void
    {
        $result = ($this->tool)('1990-06-15', '2023-01-01');

        $this->assertSame('32', $result);
    }

    public function test_calculate_age_in_years_after_birthday(): void
    {
        $result = ($this->tool)('1990-01-01', '2023-06-15');

        $this->assertSame('33', $result);
    }

    public function test_calculate_age_in_months(): void
    {
        $result = ($this->tool)('2022-01-01', '2023-07-01', 'months');

        $this->assertSame('18', $result);
    }

    public function test_calculate_age_in_days_counts_every_day_across_months(): void
    {
        $result = ($this->tool)('2022-12-25', '2023-03-10', 'days');

        $this->assertSame('75', $result);
    }

    public function test_calculate_age_all(): void
    {
        $result = ($this->tool)('1990-03-15', '2023-07-20', 'all');

        $this->assertSame([
            'years' => 33,
            'months' => 4,
            'days' => 5,
            'total_days' => 12180,
            'next_birthday' => '2024-03-15',
        ], json_decode($result, true));
    }

    public function test_the_next_birthday_of_someone_born_on_a_leap_day_falls_on_the_next_leap_day(): void
    {
        $result = json_decode(($this->tool)('2000-02-29', '2023-03-15', 'all'), true);

        $this->assertSame(23, $result['years']);
        $this->assertSame('2024-02-29', $result['next_birthday']);
    }

    public function test_on_the_birthday_itself_the_next_birthday_is_a_year_away(): void
    {
        $result = json_decode(($this->tool)('1990-06-15', '2023-06-15', 'all'), true);

        $this->assertSame([33, 0, 0], [$result['years'], $result['months'], $result['days']]);
        $this->assertSame('2024-06-15', $result['next_birthday']);
    }

    public function test_calculate_age_with_current_date(): void
    {
        $birthdate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-20 years -2 days')->format('Y-m-d');

        $this->assertSame('20', ($this->tool)($birthdate));
    }

    public function test_calculate_age_with_timestamp(): void
    {
        $birthTimestamp = '631152000'; // 1990-01-01 00:00:00 UTC
        $refTimestamp = '1672531200';  // 2023-01-01 00:00:00 UTC

        $result = ($this->tool)($birthTimestamp, $refTimestamp);

        $this->assertSame('33', $result);
    }

    public function test_calculate_age_with_timezone(): void
    {
        $result = ($this->tool)('1990-01-01 12:00:00', '2023-01-01 06:00:00', null, 'America/New_York');

        $this->assertSame('32', $result); // Should be 32 because 6 AM EST is before 12 PM EST on same date
    }

    public function test_calculate_age_leap_year(): void
    {
        // Born on leap day
        $result = ($this->tool)('2020-02-29', '2023-02-28');

        $this->assertSame('2', $result); // Should be 2 years (birthday not reached yet)
    }

    public function test_calculate_age_exact_birthday(): void
    {
        $result = ($this->tool)('1990-06-15', '2023-06-15');

        $this->assertSame('33', $result);
    }

    public function test_calculate_age_same_date(): void
    {
        $result = ($this->tool)('2023-01-01', '2023-01-01');

        $this->assertSame('0', $result);
    }

    public function test_invalid_birthdate(): void
    {
        $result = ($this->tool)('invalid-date', '2023-01-01');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_invalid_reference_date(): void
    {
        $result = ($this->tool)('1990-01-01', 'invalid-date');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('invalid-date', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('1990-01-01', '2023-01-01', null, 'Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('calculate_age', $this->tool->getName());
        $this->assertSame('Calculate age in years, months, and days from birthdate to a reference date', $this->tool->getDescription());

        $this->assertSame(
            ['birthdate', 'reference_date', 'unit', 'timezone'],
            array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $this->tool->getProperties())
        );
        $this->assertSame(['birthdate'], $this->tool->getRequiredProperties());
    }
}
