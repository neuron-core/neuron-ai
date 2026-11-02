<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

/**
 * What the model reads for a tool call whose outcome nobody knows: its process
 * died before the result was saved, and the effect may still have landed.
 */
trait ReportsInterruptedCall
{
    protected function interruptedResult(ToolCall $call): string|ToolOutput
    {
        return ToolOutput::error(
            'The call was interrupted before its result was recorded: it may or may not have taken effect.'
        );
    }
}
