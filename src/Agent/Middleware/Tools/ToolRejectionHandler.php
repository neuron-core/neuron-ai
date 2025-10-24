<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Middleware\Tools;

/**
 * Serializable tool rejection handler.
 *
 * This class replaces closures in rejected tools to make them serializable
 * when workflows need to be persisted during interruptions.
 */
class ToolRejectionHandler
{
    public function __construct(
        private readonly string $rejectionMessage
    ) {
    }

    public function __invoke(mixed ...$args): string
    {
        return $this->rejectionMessage;
    }
}
