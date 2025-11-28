<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Responses;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Providers\BasicStreamState;

use function array_values;

class StreamState extends BasicStreamState
{
    public function addContentBlock(string $id, ContentBlockInterface $block): void
    {
        $this->blocks[$id] = $block;
    }

    public function updateContentBlock(string $id, string $content): void
    {
        if (!isset($this->blocks[$id])) {
            $this->blocks[$id] = new TextContent($content);
        } else {
            $this->blocks[$id]->accumulateContent($content);
        }
    }

    public function composeToolCalls(array $event): void
    {
        if (isset($event['item']['id'])) {
            $this->toolCalls[$event['item']['id']] = [
                'name' => $event['item']['name'],
                'arguments' => $event['item']['arguments'] ?? null,
                'call_id' => $event['item']['call_id'],
            ];
        } else {
            $this->toolCalls[$event['item_id']]['arguments'] = $event['arguments'];
        }
    }

    public function getToolCalls(): array
    {
        return array_values($this->toolCalls);
    }
}
