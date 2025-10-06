<?php

namespace NeuronAI\Tools;

use Fiber;

trait HandleAsync
{
    /**
     * @throws \Throwable
     */
    protected function suspend(): void
    {
        Fiber::suspend();
    }
}
