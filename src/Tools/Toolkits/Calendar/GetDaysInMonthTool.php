<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\Calendar;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetDaysInMonthTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_days_in_month',
            description: 'Get the number of days in a specific month and year',
        );
    }

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'month',
                type: PropertyType::NUMBER,
                description: 'Month number (1-12)',
                required: true,
            ),
            ToolProperty::make(
                name: 'year',
                type: PropertyType::NUMBER,
                description: 'Year (4-digit year)',
                required: true,
            ),
        ];
    }

    public function __invoke(int $month, int $year): string
    {
        try {
            if ($month < 1 || $month > 12) {
                throw new \InvalidArgumentException('Month must be between 1 and 12');
            }

            $daysInMonth = \cal_days_in_month(\CAL_GREGORIAN, $month, $year);
            $monthName = \date('F', \mktime(0, 0, 0, $month, 1, $year));
            $isLeapYear = ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);

            return \json_encode([
                'month' => $month,
                'month_name' => $monthName,
                'year' => $year,
                'days_in_month' => $daysInMonth,
                'is_leap_year' => $isLeapYear,
                'first_day' => "{$year}-" . \str_pad((string) $month, 2, '0', \STR_PAD_LEFT) . "-01",
                'last_day' => "{$year}-" . \str_pad((string) $month, 2, '0', \STR_PAD_LEFT) . "-" . \str_pad((string) $daysInMonth, 2, '0', \STR_PAD_LEFT),
            ]);
        } catch (\Exception $e) {
            return "Error: {$e->getMessage()}";
        }
    }
}
