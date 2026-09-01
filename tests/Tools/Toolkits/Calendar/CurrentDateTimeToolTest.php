<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Calendar;

use NeuronAI\Tools\Toolkits\Calendar\CurrentDateTimeTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;
use DateTime;
use DateTimeZone;

use function array_map;

class CurrentDateTimeToolTest extends TestCase
{
    private CurrentDateTimeTool $tool;

    protected function setUp(): void
    {
        $this->tool = new CurrentDateTimeTool();
    }

    public function test_get_current_date_time_with_defaults(): void
    {
        $result = ($this->tool)();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);

        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $result, new DateTimeZone('UTC'));
        $this->assertInstanceOf(DateTime::class, $dateTime);
    }

    public function test_get_current_date_time_with_custom_timezone(): void
    {
        $result = ($this->tool)('America/New_York');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result);
    }

    public function test_get_current_date_time_with_custom_format(): void
    {
        $result = ($this->tool)(null, 'Y-m-d');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result);

        $dateTime = DateTime::createFromFormat('Y-m-d', $result);
        $this->assertInstanceOf(DateTime::class, $dateTime);
    }

    public function test_get_current_date_time_with_both_custom_options(): void
    {
        $result = ($this->tool)('Europe/London', 'H:i:s');

        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $result);
    }

    public function test_invalid_timezone(): void
    {
        $result = ($this->tool)('Invalid/Timezone');

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertEquals('current_datetime', $this->tool->getName());
        $this->assertEquals('Get the current date and time in the specified timezone and format', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('timezone', $propertyNames);
        $this->assertContains('format', $propertyNames);
    }
}
