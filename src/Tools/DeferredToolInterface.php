<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Marks a tool whose execution belongs outside the backend. The caller must
 * request external execution and receive its result instead of calling execute().
 */
interface DeferredToolInterface extends ToolInterface
{
}
