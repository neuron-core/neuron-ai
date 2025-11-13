<?php

declare(strict_types=1);

namespace NeuronAI\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\TextChunk;
use NeuronAI\Chat\Messages\Stream\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\ToolResultChunk;
use NeuronAI\Tools\ToolInterface;

/**
 * Adapter for JSON Lines (newline-delimited JSON) format.
 *
 * @see https://jsonlines.org/
 */
class JSONLinesAdapter implements StreamAdapterInterface
{
    public function transform(object $chunk): iterable
    {
        $data = match (true) {
            $chunk instanceof TextChunk => [
                'type' => 'text',
                'content' => $chunk->content,
            ],
            $chunk instanceof ReasoningChunk => [
                'type' => 'reasoning',
                'content' => $chunk->content,
            ],
            $chunk instanceof ToolCallChunk => [
                'type' => 'tool_call',
                'tools' => \array_map(
                    fn (ToolInterface $tool): array => [
                        'name' => $tool->getName(),
                        'inputs' => $tool->getInputs(),
                    ],
                    $chunk->tools
                ),
            ],
            $chunk instanceof ToolResultChunk => [
                'type' => 'tool_result',
                'tools' => \array_map(
                    fn (ToolInterface $tool): array => [
                        'name' => $tool->getName(),
                        'result' => $tool->getResult(),
                    ],
                    $chunk->tools
                ),
            ],
            default => ['type' => 'unknown']
        };

        yield \json_encode($data) . "\n";
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache',
        ];
    }

    public function start(): iterable
    {
        return [];
    }

    public function end(): iterable
    {
        yield \json_encode(['type' => 'done']) . "\n";
    }
}
