<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use NeuronAI\Providers\Gemini\ToolMapper;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ProviderTool;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

class GeminiFunctionDeclarationsListTest extends TestCase
{
    public function test_function_declarations_are_a_list_when_a_provider_tool_is_registered_first(): void
    {
        $mapped = (new ToolMapper())->map([
            new ProviderTool('google_search'),
            new ToolStub('lookup', 'Look something up'),
        ]);

        $this->assertSame(
            '{"functionDeclarations":[{"name":"lookup","description":"Look something up","parameters":{"type":"object","properties":{},"required":[]}}]}',
            json_encode($mapped, JSON_THROW_ON_ERROR),
        );
    }
}
