<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Models\Segment;
use Inspector\Models\Token;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;

use function array_key_exists;

trait HandleInferenceEvents
{
    /**
     * Open inference segments keyed by branch scope key.
     *
     * @var array<string, Segment>
     */
    protected array $inferences = [];

    public function messageSaving(object $source, string $event, MessageSaving $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $label = $this->getBaseClassName($data->message::class);
        $key = $data->message->getMetadata('__id') ?? $data->message::class;

        $this->segments[$key] = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.chathistory', "save_message( {$label} )")
            ->setColor(self::STANDARD_COLOR);

        if ($data->message instanceof AssistantMessage && $data->message->getUsage() instanceof Usage) {
            $token = new Token($this->inspector->transaction());
            $token->setInputTokens($data->message->getUsage()->inputTokens)
                ->setOutputTokens($data->message->getUsage()->outputTokens);
            $this->inspector->addEntries($token);
        }
    }

    public function messageSaved(object $source, string $event, MessageSaved $data, ?string $branchId = null): void
    {
        $key = $data->message->getMetadata('__id') ?? $data->message::class;

        if (!array_key_exists($key, $this->segments)) {
            return;
        }

        $segment = $this->segments[$key];
        $segment->addContext('Message', $this->prepareMessageItem($data->message));
        $segment->end();
        unset($this->segments[$key]);
    }

    public function inferenceStart(object $source, string $event, InferenceStart $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $label = $this->getBaseClassName($data->message::class);

        $this->inferences[$branchId] = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.inference', "inference( {$label} )")
            ->setColor(self::STANDARD_COLOR);
    }

    public function inferenceStop(object $source, string $event, InferenceStop $data, ?string $branchId = null): void
    {
        if (!isset($this->inferences[$branchId])) {
            return;
        }

        $this->inferences[$branchId]->end();
        if ($data->message instanceof Message) {
            $this->inferences[$branchId]->addContext('Message', $this->prepareMessageItem($data->message));
        }
        $this->inferences[$branchId]->addContext('Response', $this->prepareMessageItem($data->response));
        unset($this->inferences[$branchId]);
    }
}
