<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * Opt-in contract for tools that expose a structured result payload via
 * getOutput(). Tools implementing this interface can return ContentBlock
 * instances alongside (or instead of) text, and provider mappers that
 * support rich tool results will emit them natively.
 *
 * ToolInterface implementations are NOT required to implement this; the
 * classic getResult(): string path remains the default.
 */
interface HasOutput
{
    public function getOutput(): ToolOutput;
}
