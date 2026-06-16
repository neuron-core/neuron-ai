<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class ToolMetaTest extends TestCase
{
    public function test_set_and_get_meta(): void
    {
        $tool = Tool::make('test_tool', 'A test tool');
        $tool->setMeta('skill_name', 'deploy');
        $tool->setMeta('skill_step', 'execute');

        $this->assertSame('deploy', $tool->getMeta('skill_name'));
        $this->assertSame('execute', $tool->getMeta('skill_step'));
    }

    public function test_get_meta_with_default(): void
    {
        $tool = Tool::make('test_tool', 'A test tool');
        $this->assertNull($tool->getMeta('nonexistent'));
        $this->assertSame('default', $tool->getMeta('nonexistent', 'default'));
    }

    public function test_get_meta_all(): void
    {
        $tool = Tool::make('test_tool', 'A test tool');
        $tool->setMeta('key1', 'value1');
        $tool->setMeta('key2', 'value2');

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2'], $tool->getMetaAll());
    }

    public function test_meta_empty_by_default(): void
    {
        $tool = Tool::make('test_tool', 'A test tool');
        $this->assertSame([], $tool->getMetaAll());
    }

    public function test_set_meta_returns_self(): void
    {
        $tool = Tool::make('test_tool', 'A test tool');
        $result = $tool->setMeta('key', 'value');
        $this->assertSame($tool, $result);
    }
}
