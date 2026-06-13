<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Interrupt;

/**
 * An interrupt request that carries frontend handler metadata.
 *
 * Used by FrontendTool to signal the frontend to execute a handler
 * (e.g., show a modal, collect user input) and return the result.
 *
 * On resume, the same type can be provided with the handler's result
 * in the payload.
 */
class FrontendRequest extends InterruptRequest
{
    /**
     * @param string $handler Identifier for the frontend handler (e.g. 'user-picker')
     * @param array<string, mixed> $payload Data to pass to the frontend handler
     * @param string $message Human-readable reason for the interruption
     */
    public function __construct(
        protected string $handler,
        protected array $payload = [],
        string $message = '',
    ) {
        parent::__construct($message ?: "Frontend handler: {$handler}");
    }

    /**
     * Get the frontend handler identifier.
     */
    public function getHandler(): string
    {
        return $this->handler;
    }

    /**
     * Get the payload data for the frontend handler.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'handler' => $this->handler,
            'payload' => $this->payload,
            'message' => $this->message,
        ];
    }
}
