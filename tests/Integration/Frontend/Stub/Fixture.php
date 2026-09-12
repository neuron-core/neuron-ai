<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Integration\Frontend\Stub;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use PDO;
use RuntimeException;

use function array_map;
use function in_array;
use function json_decode;

/**
 * The example application around Neuron: one SQLite file holds workflow state,
 * chat history, the scenario bound to each thread, and the audit records the
 * tests read back. Every request reconstructs a fresh Agent from it.
 */
class Fixture
{
    protected PDO $pdo;

    /** Backend-owned tool names; client declarations may not shadow them. */
    protected const STABLE_TOOLS = ['server_clock', 'server_fail'];

    /** Scenarios whose frontend tools are approval-gated. */
    protected const APPROVAL_REQUIRED = ['approval-title' => ['read_title']];

    /** @return list<Tool> the backend-executed tools a scenario needs */
    protected function backendTools(string $scenario, string $threadId): array
    {
        return match ($scenario) {
            'mixed' => [new ServerClockTool($this->pdo, $threadId)],
            'backend-error' => [new ServerFailingTool()],
            default => [],
        };
    }

    public function __construct(string $databasePath)
    {
        $this->pdo = new PDO('sqlite:' . $databasePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
    }

    protected function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS workflow_store (
            "partition" TEXT NOT NULL, "key" TEXT NOT NULL, "value" TEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY ("partition", "key"))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id TEXT NOT NULL, role TEXT NOT NULL,
            content TEXT, meta TEXT, archived_at TEXT)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS threads (thread_id TEXT PRIMARY KEY, scenario TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS provider_invocations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id TEXT NOT NULL, method TEXT NOT NULL,
            messages TEXT NOT NULL, tools TEXT NOT NULL, response TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS tool_executions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id TEXT NOT NULL, call_id TEXT NOT NULL, tool TEXT NOT NULL)');
    }

    public function registerThread(string $threadId, string $scenario): void
    {
        $this->pdo->prepare('INSERT INTO threads (thread_id, scenario) VALUES (?, ?)')->execute([$threadId, $scenario]);
    }

    /**
     * The frontend tool catalog the application itself declares (Vercel has no
     * client-supplied catalog). AG-UI clients declare the same tools themselves.
     * @return list<FrontendTool>
     */
    public function frontendTools(): array
    {
        return [
            new FrontendTool('read_title', 'Read the title of the page the user is looking at.'),
            new FrontendTool('read_text', 'Read the text of an element on the page.', [
                'type' => 'object',
                'properties' => ['selector' => ['type' => 'string', 'description' => 'CSS selector']],
                'required' => ['selector'],
            ]),
            new FrontendTool('probe', 'Return a probe value of the requested kind.', [
                'type' => 'object',
                'properties' => ['kind' => ['type' => 'string', 'enum' => ['object', 'array', 'false', 'zero', 'null', 'throw']]],
                'required' => ['kind'],
            ]),
        ];
    }

    /**
     * Reconstruct the agent for a thread: persisted state, history, the scenario's
     * provider and backend tools, then the frontend catalog under the application's
     * tool policy (no shadowing of backend tools, approval where the scenario says so).
     * @param list<FrontendTool> $frontendTools
     */
    public function agent(string $threadId, array $frontendTools = []): Agent
    {
        $scenario = $this->scenario($threadId);

        $agent = Agent::make();
        $agent->setChatHistory(new SQLChatHistory($this->pdo, $threadId));
        $agent->setPersistence(new DatabasePersistence($this->pdo));
        $agent->setAiProvider(new ScenarioProvider($this->pdo, $threadId, $scenario));
        $agent->addTool($this->backendTools($scenario, $threadId));

        foreach ($frontendTools as $tool) {
            if (in_array($tool->getName(), self::STABLE_TOOLS, true)) {
                throw new RuntimeException("Frontend tool '{$tool->getName()}' collides with a backend tool.");
            }
            if (in_array($tool->getName(), self::APPROVAL_REQUIRED[$scenario] ?? [], true)) {
                $tool->requireApproval();
            }
            $agent->addTool($tool);
        }
        return $agent;
    }

    protected function scenario(string $threadId): string
    {
        $statement = $this->pdo->prepare('SELECT scenario FROM threads WHERE thread_id = ?');
        $statement->execute([$threadId]);
        $scenario = $statement->fetchColumn();
        if ($scenario === false) {
            throw new RuntimeException("Thread '{$threadId}' was not registered with a scenario.");
        }
        return (string) $scenario;
    }

    /** @return array<string, mixed> */
    public function observe(string $threadId): array
    {
        $run = (new WorkflowExecutor())->inspect($this->agent($threadId));

        $invocations = $this->pdo->prepare('SELECT method, messages, tools, response FROM provider_invocations WHERE thread_id = ? ORDER BY id');
        $invocations->execute([$threadId]);
        $executions = $this->pdo->prepare('SELECT call_id, tool FROM tool_executions WHERE thread_id = ? ORDER BY id');
        $executions->execute([$threadId]);
        $history = $this->pdo->prepare('SELECT role, content, meta FROM chat_messages WHERE thread_id = ? ORDER BY id');
        $history->execute([$threadId]);

        return [
            'run' => $run === null ? null : [
                'runId' => $run->runId,
                'status' => $run->status->value,
                'executionAttempt' => $run->executionAttempt,
                'interrupts' => array_map(fn (InterruptRequest $request): string => $request::class, $run->interrupts),
            ],
            'invocations' => array_map(fn (array $row): array => [
                'method' => $row['method'],
                'messages' => json_decode($row['messages'], true),
                'tools' => json_decode($row['tools'], true),
                'response' => json_decode($row['response'], true),
            ], $invocations->fetchAll(PDO::FETCH_ASSOC)),
            'executions' => $executions->fetchAll(PDO::FETCH_ASSOC),
            'history' => array_map(fn (array $row): array => [
                'role' => $row['role'],
                'content' => json_decode((string) $row['content'], true),
                'meta' => json_decode((string) $row['meta'], true),
            ], $history->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }
}
