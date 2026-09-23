<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Chat\Messages\Message;

use function array_map;
use function array_search;
use function array_slice;
use function count;
use function max;

/**
 * The loadAll() window for stores that hold a thread as an ordered list.
 */
trait PaginatesMessages
{
    /**
     * @param Message[] $messages the whole thread, in insertion order
     * @return Message[]
     */
    protected function paginate(array $messages, ?int $limit, ?string $before): array
    {
        if ($before !== null) {
            $position = array_search($before, array_map(fn (Message $message): string => $message->getId(), $messages), true);

            if ($position === false) {
                return [];
            }

            $messages = array_slice($messages, 0, $position);
        }

        return $limit === null ? $messages : array_slice($messages, max(0, count($messages) - $limit));
    }
}
