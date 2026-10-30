<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Stub;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\RAG\Nodes\PreProcessNode;

/**
 * Closes a question an earlier run left unanswered in Italian.
 */
class ItalianPreProcessNode extends PreProcessNode
{
    public const INTERRUPTED_ANSWER = 'La mia risposta precedente è stata interrotta prima di essere completata.';

    protected function interruptedAnswer(): AssistantMessage
    {
        return new AssistantMessage(self::INTERRUPTED_ANSWER);
    }
}
