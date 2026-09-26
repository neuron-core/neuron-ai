<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\ProviderTool;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ProviderToolTest extends TestCase
{
    public function test_describes_a_provider_hosted_tool(): void
    {
        $tool = ProviderTool::make('web_search_20250305', 'web_search', ['max_uses' => 3]);

        $this->assertSame('web_search_20250305', $tool->getType());
        $this->assertSame('web_search', $tool->getName());
        $this->assertSame(['max_uses' => 3], $tool->getOptions());
        $this->assertTrue($tool->isVisible());
    }

    public function test_options_and_visibility_can_be_changed(): void
    {
        $tool = (new ProviderTool('code_execution'))->setOptions(['timeout' => 30])->visible(false);

        $this->assertSame(['timeout' => 30], $tool->getOptions());
        $this->assertFalse($tool->isVisible());
        $this->assertNull($tool->getName());
    }

    public function test_json_serialization(): void
    {
        $this->assertSame(
            '{"type":"mcp","name":"deepwiki","options":{"server_label":"wiki"}}',
            json_encode(new ProviderTool('mcp', 'deepwiki', ['server_label' => 'wiki']))
        );
    }
}
