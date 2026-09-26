<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP\Stub;

use NeuronAI\MCP\McpSessionLostException;
use NeuronAI\Testing\FakeMcpTransport;

/**
 * A fake transport whose session is gone when chosen requests are sent, the way an
 * HTTP server answers 404 to an expired session ID.
 */
class SessionLosingMcpTransport extends FakeMcpTransport
{
    public int $connections = 0;

    /**
     * @var array<string, int> Sends of each method still to be lost.
     */
    protected array $losses = [];

    public function loseSessionOn(string $method, int $times = 1): self
    {
        $this->losses[$method] = $times;

        return $this;
    }

    public function connect(): void
    {
        $this->connections++;
        parent::connect();
    }

    public function send(array $data): void
    {
        parent::send($data);

        $method = $data['method'] ?? '';
        if (($this->losses[$method] ?? 0) > 0) {
            $this->losses[$method]--;
            throw new McpSessionLostException('The MCP session has expired');
        }
    }
}
