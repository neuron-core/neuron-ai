<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Stub;

class InvokableDeferredTool extends DeferredToolStub
{
    public bool $invoked = false;

    public function __invoke(string $query): string
    {
        $this->invoked = true;

        return $query;
    }
}
