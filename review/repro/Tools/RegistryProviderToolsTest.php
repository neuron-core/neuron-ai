<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\ProviderTool;
use NeuronAI\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

class RegistryProviderToolsTest extends TestCase
{
    public function test_distinct_unnamed_provider_tools_are_all_registered(): void
    {
        $registry = new ToolRegistry();

        $webSearch = new ProviderTool('web_search');
        $codeExecution = new ProviderTool('code_execution');
        $registry->add($webSearch);
        $registry->add($codeExecution);

        $this->assertSame([$webSearch, $codeExecution], $registry->all());
    }

    public function test_unnamed_provider_tool_is_registered_next_to_ones_given_at_construction(): void
    {
        $googleSearch = new ProviderTool('google_search');
        $registry = new ToolRegistry([$googleSearch]);

        $codeExecution = new ProviderTool('code_execution');
        $registry->add($codeExecution);

        $this->assertSame([$googleSearch, $codeExecution], $registry->all());
    }

    public function test_named_tools_are_still_deduplicated(): void
    {
        $first = new ProviderTool('web_search_20250305', 'web_search');
        $registry = new ToolRegistry([$first]);

        $registry->add(new ProviderTool('web_search_20250305', 'web_search'));

        $this->assertSame([$first], $registry->all());
    }
}
