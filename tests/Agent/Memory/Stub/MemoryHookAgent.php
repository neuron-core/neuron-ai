<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Memory\Stub;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Memory\MemoryInterface;

class MemoryHookAgent extends Agent
{
    public int $memoryCalls = 0;

    public function __construct(protected MemoryInterface $defaultMemory)
    {
        parent::__construct(threadId: 'thread-hook');
    }

    protected function memory(): MemoryInterface
    {
        $this->memoryCalls++;

        return $this->defaultMemory;
    }
}
