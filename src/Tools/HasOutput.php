<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Opt-in interface for tools that expose a structured result via getOutput().
 * Provider mappers honor blocks where the underlying API supports them.
 */
interface HasOutput
{
    public function getOutput(): ToolOutput;
}
