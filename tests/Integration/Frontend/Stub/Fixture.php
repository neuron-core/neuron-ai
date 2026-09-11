<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Integration\Frontend\Stub;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\SQLChatHistory;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use PDO;
use RuntimeException;

use function array_map;
use function json_decode;

/**
 * The example application around Neuron: one SQLite file holds workflow state,
 * chat history, the scenario bound to each thread, and the audit records the
 * tests read back. Every request reconstructs a fresh Agent from it.
 */
class Fixture
{
    protected PDO $pdo;

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
    }

    public function registerThread(string $threadId, string $scenario): void
    {
        $this->pdo->prepare('INSERT INTO threads (thread_id, scenario) VALUES (?, ?)')->execute([$threadId, $scenario]);
    }

    public function agent(string $threadId): Agent
    {
        $scenario = $this->pdo->prepare('SELECT scenario FROM threads WHERE thread_id = ?');
        $scenario->execute([$threadId]);
        $name = $scenario->fetchColumn();
        if ($name === false) {
            throw new RuntimeException("Thread '{$threadId}' was not registered with a scenario.");
        }

        $agent = Agent::make();
        $agent->setChatHistory(new SQLChatHistory($this->pdo, $threadId));
        $agent->setPersistence(new DatabasePersistence($this->pdo));
        $agent->setAiProvider(new ScenarioProvider($this->pdo, $threadId, (string) $name));
        return $agent;
    }

    /** @return array<string, mixed> */
    public function observe(string $threadId): array
    {
        $agent = $this->agent($threadId);
        $run = (new WorkflowExecutor())->inspect($agent);

        $invocations = $this->pdo->prepare('SELECT method, messages, tools, response FROM provider_invocations WHERE thread_id = ? ORDER BY id');
        $invocations->execute([$threadId]);
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
            'history' => array_map(fn (array $row): array => [
                'role' => $row['role'],
                'content' => json_decode((string) $row['content'], true),
                'meta' => json_decode((string) $row['meta'], true),
            ], $history->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }
}
