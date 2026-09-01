<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Tools\Tool;

class IntentDummyTool extends Tool
{
    protected string $name = 'intent_dummy';

    protected ?string $description = 'Dummy tool for intent tests';

    public function __invoke(): string
    {
        return 'ok';
    }
}
