<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Adapters;

use NeuronAI\Agent\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Agent\Adapters\Events\CustomStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Agent\Adapters\Events\StreamEventInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Exceptions\StreamAdapterException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

/**
 * Neuron's own wire vocabulary, for a destination that speaks no UI protocol:
 * each yielded object becomes one event, named after its kind and carrying its
 * own data. A one-to-one mapping needs no state and no start or end frames: a
 * channel already reports the segment's end through its lifecycle events.
 */
class AgentChunkAdapter implements CustomizableStreamAdapterInterface
{
    use MapsStreamEvents;

    public function reset(): void
    {
    }

    /**
     * @throws StreamAdapterException
     */
    public function transform(object $chunk): iterable
    {
        [$resolved, $streamEvent] = $this->resolveStreamEvent($chunk);

        if ($resolved) {
            return $streamEvent instanceof StreamEventInterface ? [$this->encodeStreamEvent($streamEvent)] : [];
        }

        if (!$chunk instanceof StreamChunk) {
            return [];
        }

        $type = $this->chunkType($chunk);

        return $type === null ? [] : [new ProtocolEvent($type, $chunk->toArray())];
    }

    protected function chunkType(StreamChunk $chunk): ?string
    {
        return match (true) {
            $chunk instanceof TextChunk => 'text',
            $chunk instanceof ReasoningChunk => 'reasoning',
            $chunk instanceof ImageChunk => 'image',
            $chunk instanceof AudioChunk => 'audio',
            $chunk instanceof ToolArgumentChunk => 'tool-argument',
            $chunk instanceof ToolCallChunk => 'tool-call',
            $chunk instanceof ToolResultChunk => 'tool-result',
            default => null,
        };
    }

    /**
     * @throws StreamAdapterException
     */
    protected function encodeStreamEvent(StreamEventInterface $event): ProtocolEvent
    {
        return match (true) {
            $event instanceof StepStartedStreamEvent => new ProtocolEvent('step-started', [
                'name' => $event->name,
                'metadata' => (object) $event->metadata,
            ]),
            $event instanceof StepFinishedStreamEvent => new ProtocolEvent('step-finished', [
                'name' => $event->name,
                'metadata' => (object) $event->metadata,
            ]),
            // ProtocolEvent flattens its payload beside "type", the event
            // discriminator: the activity's own type needs another name.
            $event instanceof ActivityStreamEvent => new ProtocolEvent('activity', [
                'id' => $event->id,
                'activityType' => $event->type,
                'data' => (object) $event->data,
            ]),
            $event instanceof CustomStreamEvent => new ProtocolEvent('custom', [
                'name' => $event->name,
                'value' => $event->value,
            ]),
            default => throw new StreamAdapterException(
                'The native adapter cannot encode stream event ' . $event::class . '.'
            ),
        };
    }

    public function start(): iterable
    {
        return [];
    }

    public function end(): iterable
    {
        return [];
    }

    /**
     * The request stays nested: it describes itself with a "type" key of its
     * own, which would replace the event discriminator once flattened.
     */
    public function interrupt(InterruptRequest $request): iterable
    {
        return [new ProtocolEvent('interrupt', ['request' => $request->jsonSerialize()])];
    }

    public function error(Throwable $error): iterable
    {
        return [new ProtocolEvent('error', ['message' => $this->errorMessage($error)])];
    }

    /**
     * The failure text a client may see. Exception messages carry internals
     * (provider URLs and response bodies, file paths, run identifiers), so
     * the wire gets a neutral text; override to expose what your clients may
     * know.
     */
    protected function errorMessage(Throwable $error): string
    {
        return 'The run failed.';
    }
}
