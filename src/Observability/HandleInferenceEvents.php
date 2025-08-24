<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Agent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

trait HandleInferenceEvents
{
    public function messageSaving(Agent $agent, string $event, MessageSaving $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $label = $this->getBaseClassName($data->message::class);

        $this->segments[$this->getMessageId($data->message).'-save'] = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.chathistory', "save_message( {$label} )")
            ->setColor(self::SEGMENT_COLOR);
    }

    public function messageSaved(Agent $agent, string $event, MessageSaved $data): void
    {
        $id = $this->getMessageId($data->message).'-save';

        if (!\array_key_exists($id, $this->segments)) {
            return;
        }

        $segment = $this->segments[$id];
        $segment->addContext('Message', \array_merge(
            $data->message->jsonSerialize(),
            $data->message->getUsage() instanceof Usage ? [
                'usage' => [
                    'input_tokens' => $data->message->getUsage()->inputTokens,
                    'output_tokens' => $data->message->getUsage()->outputTokens,
                ]
            ] : []
        ));
        $segment->end();
    }

    public function inferenceStart(Agent $agent, string $event, InferenceStart $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $label = $this->getBaseClassName($data->message::class);

        $this->segments[$this->getMessageId($data->message).'-inference'] = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.inference', "inference( {$label} )")
            ->setColor(self::SEGMENT_COLOR);
    }

    public function inferenceStop(Agent $agent, string $event, InferenceStop $data): void
    {
        $id = $this->getMessageId($data->message).'-inference';

        if (\array_key_exists($id, $this->segments)) {
            $segment = $this->segments[$id]->end();
            $segment->addContext('Message', $data->message)
                ->addContext('Response', $data->response);
        }
    }
}
