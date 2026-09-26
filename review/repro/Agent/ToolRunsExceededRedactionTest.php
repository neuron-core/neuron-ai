<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_filter;
use function json_encode;

class ToolRunsExceededRedactionTest extends TestCase
{
    protected const SECRET = 'sk-live-4f9c2b7e';

    public function test_run_limit_error_names_tool_and_limit_without_leaking_arguments(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<array{string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };

        CountingTool::reset();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('lookup', 'call-1', ['query' => 'first'])]),
            new ToolCallMessage(null, [new ToolCall('lookup', 'call-2', ['query' => self::SECRET])]),
        );

        $agent = Agent::make()->setAiProvider($provider)->addTool(new CountingTool())->toolMaxRuns(1);
        $agent->subscribe(ObservabilityEvent::class, new LogListener($logger));

        try {
            $agent->chat(new UserMessage('Go'));
            $this->fail('The second lookup must exceed the run limit.');
        } catch (ToolRunsExceededException $exception) {
            $this->assertStringContainsString('Tool lookup has been executed too many times - 1', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }

        $errorRecords = array_filter($logger->records, static fn (array $record): bool => $record[0] === 'error');
        $this->assertNotEmpty($errorRecords);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($errorRecords));
    }
}
