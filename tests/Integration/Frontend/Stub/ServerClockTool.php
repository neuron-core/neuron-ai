<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Integration\Frontend\Stub;

use NeuronAI\Tools\Tool;
use PDO;

/**
 * A backend-executed tool whose every run is persisted, so tests can prove
 * local work happened exactly once and never reached the browser.
 */
class ServerClockTool extends Tool
{
    protected string $name = 'server_clock';

    protected ?string $description = 'Read the server clock.';

    public function __construct(protected PDO $pdo, protected string $threadId)
    {
    }

    public function __invoke(): string
    {
        $this->pdo->prepare('INSERT INTO tool_executions (thread_id, call_id, tool) VALUES (?, ?, ?)')
            ->execute([$this->threadId, $this->getCallId(), $this->name]);
        return '12:00';
    }
}
