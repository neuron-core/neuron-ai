<?php

declare(strict_types=1);

namespace Flowline\Protocol;

use Flowline\Event;

/**
 * Parses the incoming call request payload sent by the platform
 * when invoking a task or step.
 */
final class CallRequest
{
    /**
     * @param Event $event The primary triggering event
     * @param list<Event> $events All events (batch)
     * @param array<string, array{data?: mixed, error?: array{name: string, message: string}}> $steps Memoized step results
     * @param string $runId Unique run identifier
     * @param int $attempt Attempt number (0-indexed)
     * @param bool $disableImmediateExecution Whether immediate step execution is disabled
     * @param string $functionId The composite function ID (appName-taskId)
     * @param string $stepId The step ID being executed
     */
    public function __construct(
        public readonly Event $event,
        public readonly array $events,
        public readonly array $steps,
        public readonly string $runId,
        public readonly int $attempt,
        public readonly bool $disableImmediateExecution,
        public readonly string $functionId,
        public readonly string $stepId,
    ) {}

    /**
     * Build from raw HTTP payload and query parameters.
     */
    public static function fromPayload(string $functionId, string $stepId, array $payload): self
    {
        $events = array_map(
            fn (array $e): Event => Event::fromArray($e),
            $payload['events'] ?? [$payload['event']],
        );

        return new self(
            event: Event::fromArray($payload['event']),
            events: $events,
            steps: $payload['steps'] ?? [],
            runId: $payload['ctx']['run_id'] ?? '',
            attempt: $payload['ctx']['attempt'] ?? 0,
            disableImmediateExecution: $payload['ctx']['disable_immediate_execution'] ?? false,
            functionId: $functionId,
            stepId: $stepId,
        );
    }
}
