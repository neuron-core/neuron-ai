<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\RequestRecord;

use function array_map;
use function array_reverse;
use function array_slice;
use function implode;

/**
 * Reads what a provider received with the question of a turn: the context is
 * sent in the question's own message, after the question's content.
 */
trait ReadsTurnContext
{
    /**
     * @return string[] the text of each block of a message, in order
     */
    protected function blocks(Message $message): array
    {
        return array_map(
            static fn (ContentBlockInterface $block): string => $block->getContent(),
            $message->getContentBlocks(),
        );
    }

    /**
     * The context a request carried: every block of the turn's question after the first.
     */
    protected function turnContext(RequestRecord $request): string
    {
        foreach (array_reverse($request->messages) as $message) {
            if ($message instanceof UserMessage && !$message instanceof ToolResultMessage) {
                return implode("\n", array_slice($this->blocks($message), 1));
            }
        }

        return '';
    }
}
