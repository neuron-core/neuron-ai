<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use ErrorException;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function iterator_to_array;
use function restore_error_handler;
use function set_error_handler;

class VercelAIAdapterSeedReproTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function malformedToolCallIds(): iterable
    {
        yield 'array' => [['call_2']];
        yield 'float' => [1.5];
        yield 'boolean' => [true];
    }

    #[DataProvider('malformedToolCallIds')]
    public function test_parts_with_a_non_string_tool_call_id_are_ignored_without_php_errors(mixed $toolCallId): void
    {
        set_error_handler(static fn (int $severity, string $message): never => throw new ErrorException($message, 0, $severity));
        try {
            $adapter = new VercelAIAdapter('assistant', [
                ['type' => 'tool-browser', 'toolCallId' => $toolCallId, 'state' => 'output-available'],
                ['type' => 'tool-browser', 'toolCallId' => 'call_1', 'state' => 'output-available'],
            ]);
        } finally {
            restore_error_handler();
        }

        $request = (new ToolResultsRequest([
            new ToolCall('browser', 'call_1', deferred: true),
            new ToolCall('browser', 'call_2', deferred: true),
        ]))->withId(1);
        $events = array_map(
            static fn (ProtocolEvent $event): array => $event->jsonSerialize(),
            iterator_to_array($adapter->interrupt($request), false),
        );

        $dispatched = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'tool-input-available'));
        $this->assertSame(['call_2'], array_column($dispatched, 'toolCallId'));
    }
}
