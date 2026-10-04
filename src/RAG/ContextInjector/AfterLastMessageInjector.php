<?php

declare(strict_types=1);

namespace NeuronAI\RAG\ContextInjector;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;

use const PHP_EOL;

/**
 * Injects retrieved context as a TextContent block AFTER the existing content
 * of the last user message.
 *
 * The resulting content block order in the last UserMessage will be:
 *   [...original blocks, context block]
 *
 * The $instructions string is returned unchanged.
 */
class AfterLastMessageInjector extends AbstractMessageInjector
{
    public function inject(array $documents, string $instructions, AgentState $state): string
    {
        $messages = $state->getChatHistory()->getMessages();

        foreach (array_reverse($messages) as $message) {
            if (! $message instanceof UserMessage) {
                continue;
            }

            $context = $this->formatContext($documents);
            $message->addContent(
                new TextContent('<EXTRA-CONTEXT>' . PHP_EOL . $context . PHP_EOL . '</EXTRA-CONTEXT>')
            );

            break;
        }

        return $instructions;
    }
}
