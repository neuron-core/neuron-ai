<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;

class Extracted extends ObservabilityEvent
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(public Message $message, public array $schema, public ?string $json)
    {
    }

    public function name(): string
    {
        return 'structured-extracted';
    }

    public function toArray(): array
    {
        return [
            'message' => $this->message->jsonSerialize(),
            'schema' => $this->schema,
            'json' => $this->json,
        ];
    }
}
