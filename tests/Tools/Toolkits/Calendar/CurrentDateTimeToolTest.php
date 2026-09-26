<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function time;

class CurrentDateTimeToolTest extends TestCase
{
    protected CurrentDateTimeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new CurrentDateTimeTool();
    }

    public function test_returns_the_current_instant(): void
    {
        $before = time();
        $result = ($this->tool)(null, 'U');
        $after = time();

        $this->assertGreaterThanOrEqual($before, (int) $result);
        $this->assertLessThanOrEqual($after, (int) $result);
    }

    public function test_defaults_to_utc_in_the_standard_format(): void
    {
        $before = time();
        $result = ($this->tool)();
        $after = time();

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $result, new DateTimeZone('UTC'));
        $this->assertInstanceOf(DateTimeImmutable::class, $parsed);
        $this->assertGreaterThanOrEqual($before, $parsed->getTimestamp());
        $this->assertLessThanOrEqual($after, $parsed->getTimestamp());
    }

    public function test_the_default_timezone_is_utc_whatever_the_server_default(): void
    {
        $serverDefault = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            $this->assertSame('UTC', ($this->tool)(null, 'e'));
        } finally {
            date_default_timezone_set($serverDefault);
        }
    }

    public function test_is_expressed_in_the_requested_timezone(): void
    {
        $this->assertSame('Asia/Kolkata +05:30', ($this->tool)('Asia/Kolkata', 'e P'));
    }

    public function test_applies_the_requested_format(): void
    {
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', ($this->tool)('Europe/London', 'H:i:s'));
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('Invalid/Timezone');

        $this->assertStringStartsWith('Error: ', $result);
        $this->assertStringContainsString('Invalid/Timezone', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('current_datetime', $this->tool->getName());
        $this->assertSame('Get the current date and time in the specified timezone and format', $this->tool->getDescription());
        $this->assertSame(['timezone', 'format'], array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties()));
        $this->assertSame([], $this->tool->getRequiredProperties());
    }
}
