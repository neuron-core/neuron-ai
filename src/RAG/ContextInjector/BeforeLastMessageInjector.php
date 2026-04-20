<?php

declare(strict_types=1);

namespace NeuronAI\RAG\ContextInjector;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;

use const PHP_EOL;

/**
 * Injects retrieved context as a TextContent block BEFORE the existing content
 * of the last user message.
 *
 * The resulting content block order in the last UserMessage will be:
 *   [context block, ...original blocks]
 *
 * The $instructions string is returned unchanged.
 */
class BeforeLastMessageInjector extends AbstractMessageInjector
{
    public function inject(array $documents, string $instructions, AgentState $state): string
    {
        $messages = $state->getChatHistory()->getMessages();

        foreach (array_reverse($messages) as $message) {
            if (! $message instanceof UserMessage) {
                continue;
            }

            $context = $this->formatContext($documents);
            $contextBlock = new TextContent('<EXTRA-CONTEXT>' . PHP_EOL . $context . PHP_EOL . '</EXTRA-CONTEXT>');

            $existing = $message->getContentBlocks();
            // Rebuild: context first, then original blocks
            $message->setContents($contextBlock);
            foreach ($existing as $block) {
                $message->addContent($block);
            }

            break;
        }

        return $instructions;
    }
}
