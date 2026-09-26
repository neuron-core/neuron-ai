<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use ErrorException;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Exceptions\NeuronException;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function restore_error_handler;
use function set_error_handler;

class AGUIAdapterSeedReproTest extends TestCase
{
    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function malformedMessages(): iterable
    {
        yield 'message without id' => [[['role' => 'user', 'content' => 'Hi']]];
        yield 'array id' => [[['id' => ['x'], 'role' => 'user', 'content' => 'Hi']]];
        yield 'tool message without toolCallId' => [[['id' => 'm', 'role' => 'tool', 'content' => 'x']]];
        yield 'toolCalls is a string' => [[['id' => 'm', 'role' => 'assistant', 'toolCalls' => 'x']]];
        yield 'tool call without id' => [[['id' => 'm', 'role' => 'assistant', 'toolCalls' => [['type' => 'function']]]]];
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    #[DataProvider('malformedMessages')]
    public function test_malformed_client_messages_fail_with_a_framework_exception(array $messages): void
    {
        set_error_handler(static fn (int $severity, string $message): never => throw new ErrorException($message, 0, $severity));
        try {
            new AGUIAdapter('thread', 'run', $messages);
            $this->fail('A malformed RunAgentInput.messages entry must be rejected.');
        } catch (NeuronException) {
            $this->addToAssertionCount(1);
        } finally {
            restore_error_handler();
        }
    }

    // Shows the silent data loss on current code when warnings are not fatal (production default).
    public function test_messages_without_id_do_not_silently_collapse_in_snapshot(): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $adapter = new AGUIAdapter('thread', 'run', [
                ['role' => 'user', 'content' => 'first'],
                ['role' => 'user', 'content' => 'second'],
            ]);
        } catch (NeuronException) {
            $this->addToAssertionCount(1);
            return;
        } finally {
            restore_error_handler();
        }
        $events = [];
        foreach ($adapter->interrupt((new WaitForEventRequest('custom'))->withId(1)) as $event) {
            $events[] = $event->jsonSerialize();
        }
        $snapshot = array_values(array_filter($events, static fn (array $event): bool => $event['type'] === 'MESSAGES_SNAPSHOT'))[0];
        $this->assertCount(2, $snapshot['messages']);
    }
}
