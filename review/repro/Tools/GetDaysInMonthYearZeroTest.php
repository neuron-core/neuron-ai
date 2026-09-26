<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\GetDaysInMonthTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GetDaysInMonthYearZeroTest extends TestCase
{
    public static function unsupportedYears(): array
    {
        return [
            'year zero' => [0],
            'negative year' => [-1],
        ];
    }

    #[DataProvider('unsupportedYears')]
    public function test_an_unsupported_year_is_reported_as_an_error_string(int $year): void
    {
        $this->assertStringStartsWith('Error: ', (new GetDaysInMonthTool())(2, $year));
    }
}
