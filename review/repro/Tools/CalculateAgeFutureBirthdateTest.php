<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\CalculateAgeTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalculateAgeFutureBirthdateTest extends TestCase
{
    public static function units(): array
    {
        return [['years'], ['months'], ['days'], ['all']];
    }

    #[DataProvider('units')]
    public function test_a_birthdate_after_the_reference_date_is_reported_as_an_error(string $unit): void
    {
        $result = (new CalculateAgeTool())('2030-01-01', '2024-01-01', $unit);

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_a_birthdate_equal_to_the_reference_date_is_age_zero(): void
    {
        $this->assertSame('0', (new CalculateAgeTool())('2024-01-01', '2024-01-01'));
    }
}
