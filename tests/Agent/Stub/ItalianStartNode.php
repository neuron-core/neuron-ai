<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Agent\Nodes\AgentStartNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;

/**
 * Closes a turn an earlier run left open in Italian.
 */
class ItalianStartNode extends AgentStartNode
{
    public const INTERRUPTED_RESULT = 'La chiamata è stata interrotta prima che il risultato fosse registrato: potrebbe essere stata eseguita oppure no.';

    public const INTERRUPTED_ANSWER = 'La mia risposta precedente è stata interrotta prima di essere completata.';

    protected function interruptedResult(ToolCall $call): string|ToolOutput
    {
        return ToolOutput::error(self::INTERRUPTED_RESULT);
    }

    protected function interruptedAnswer(): AssistantMessage
    {
        return new AssistantMessage(self::INTERRUPTED_ANSWER);
    }
}
