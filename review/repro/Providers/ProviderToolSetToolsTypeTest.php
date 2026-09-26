<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tools\ProviderTool;
use PHPUnit\Framework\TestCase;

// Static repro: passes under PHPUnit, but `vendor/bin/phpstan analyse` on this file reports argument.type on current code.
class ProviderToolSetToolsTypeTest extends TestCase
{
    public function test_provider_tools_are_accepted_by_set_tools(): void
    {
        $provider = new OpenAI('key', 'gpt-4o');

        $this->assertSame($provider, $provider->setTools([ProviderTool::make('web_search')]));
    }
}
