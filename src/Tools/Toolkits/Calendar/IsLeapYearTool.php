<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class IsLeapYearTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'is_leap_year',
            description: 'Check if a given year is a leap year',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'year',
                type: PropertyType::NUMBER,
                description: 'Year to check (4-digit year)',
                required: true,
            ),
        ];
    }

    public function __invoke(int $year): string
    {
        $isLeapYear = ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);

        return \json_encode([
            'year' => $year,
            'is_leap_year' => $isLeapYear,
            'days_in_year' => $isLeapYear ? 366 : 365,
            'february_days' => $isLeapYear ? 29 : 28,
        ]);
    }
}
