<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\LogListener;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\CountingTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_column;
use function array_filter;
use function array_intersect_key;
use function array_slice;
use function array_values;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * An approval turn spans two execution segments in two processes. Logs from
 * both must correlate by the documented identity (workflowId, runId,
 * executionAttempt, status), staging the decisions must log nothing, and the
 * resumed segment must report the approved call it executed.
 */
class AgentApprovalLogContractTest extends TestCase
{
    protected const IDENTITY = ['workflowId' => true, 'runId' => true, 'executionAttempt' => true, 'status' => true];

    protected InMemoryPersistence $persistence;

    protected InMemoryMessageStore $messages;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->messages = new InMemoryMessageStore();
    }

    /**
     * @return AbstractLogger&object{records: list<array{message: string, context: array<string, mixed>}>}
     */
    protected function recordingLogger(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
    }

    protected function agent(FakeAIProvider $provider, AbstractLogger $logger): Agent
    {
        $agent = Agent::make(workflowId: 'logged-thread')
            ->setAiProvider($provider)
            ->setPersistence($this->persistence)
            ->setMessageStore($this->messages)
            ->addTool((new CountingTool())->requireApproval());
        $agent->subscribe(ObservabilityEvent::class, new LogListener($logger));

        return $agent;
    }

    public function test_both_segments_of_an_approval_turn_log_the_same_run(): void
    {
        $pausing = $this->recordingLogger();
        $this->agent(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('lookup', 'call_1', ['query' => 'php']),
        ])), $pausing)->chat(new UserMessage('Look up php'));

        $runId = $this->persistedRunId();
        $pause = ['workflowId' => 'logged-thread', 'runId' => $runId, 'executionAttempt' => 1, 'status' => 'suspended'];
        [$interrupted, $pausedEnd] = $this->lastRecords($pausing, 2);
        $this->assertSame(['workflow-interrupted', 'workflow-end'], [$interrupted['message'], $pausedEnd['message']]);
        $this->assertSame($pause, array_intersect_key($interrupted['context'], self::IDENTITY));
        $this->assertSame($pause, array_intersect_key($pausedEnd['context'], self::IDENTITY));
        $this->assertSame(
            ['call_1'],
            array_column($this->json($interrupted['context']['interrupt'])['actions'], 'id'),
            'The interruption record names the calls awaiting a decision.',
        );

        $resuming = $this->recordingLogger();
        $pending = $this->agent(new FakeAIProvider(new AssistantMessage('Done')), $resuming)
            ->submitApprovalDecisions(['call_1' => 'approve']);
        $this->assertCount(0, $resuming->records, 'Staging decisions emits no execution events.');

        $pending->run();

        $tools = array_values(array_filter(
            $resuming->records,
            static fn (array $record): bool => $record['message'] === 'tool-calling' || $record['message'] === 'tool-called',
        ));
        $this->assertSame(['tool-calling', 'tool-called'], array_column($tools, 'message'));
        $called = $this->json($tools[1]['context']['tool']);
        $this->assertSame(['call_1', 'approved', 'Results for: php'], [$called['callId'], $called['approval'], $called['result']]);

        [$completedEnd] = $this->lastRecords($resuming, 1);
        $this->assertSame('workflow-end', $completedEnd['message']);
        $this->assertSame(
            ['workflowId' => 'logged-thread', 'runId' => $runId, 'executionAttempt' => 2, 'status' => 'completed'],
            array_intersect_key($completedEnd['context'], self::IDENTITY),
        );
    }

    protected function persistedRunId(): string
    {
        $runId = $this->agent(new FakeAIProvider(), $this->recordingLogger())->inspect()?->runId;
        $this->assertIsString($runId);

        return $runId;
    }

    /**
     * @param AbstractLogger&object{records: list<array{message: string, context: array<string, mixed>}>} $logger
     * @return list<array{message: string, context: array<string, mixed>}>
     */
    protected function lastRecords(AbstractLogger $logger, int $count): array
    {
        return array_slice($logger->records, -$count);
    }

    /**
     * Log contexts carry JSON-serializable values; decode them as a log shipper would.
     *
     * @return array<string, mixed>
     */
    protected function json(mixed $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
