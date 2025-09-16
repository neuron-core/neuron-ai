<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Models\Segment;
use NeuronAI\Agent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

trait HandleInferenceEvents
{
    protected Segment $message;
    protected Segment $inference;

    public function messageSaving(Agent $agent, string $event, MessageSaving $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $label = $this->getBaseClassName($data->message::class);

        $this->message = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.chathistory', "save_message( {$label} )")
            ->setColor(self::STANDARD_COLOR);
    }

    public function messageSaved(Agent $agent, string $event, MessageSaved $data): void
    {
        if (!isset($this->message)) {
            return;
        }

        $this->message->end();
        $this->message->addContext('Message', \array_merge(
            $data->message->jsonSerialize(),
            $data->message->getUsage() instanceof Usage ? [
                'usage' => [
                    'input_tokens' => $data->message->getUsage()->inputTokens,
                    'output_tokens' => $data->message->getUsage()->outputTokens,
                ]
            ] : []
        ));
    }

    public function inferenceStart(Agent $agent, string $event, InferenceStart $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $label = $this->getBaseClassName($data->message::class);

        $this->inference = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.inference', "inference( {$label} )")
            ->setColor(self::STANDARD_COLOR);
    }

    public function inferenceStop(Agent $agent, string $event, InferenceStop $data): void
    {
        if (isset($this->inference)) {
            $this->inference->end();
            $this->inference->addContext('Message', $data->message)
                ->addContext('Response', $data->response);
        }
    }
}
