<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

interface McpTransportInterface
{
    public function connect(): void;

    /**
     * @param array<string, mixed> $data
     */
    public function send(array $data): void;

    /**
     * @return  array<string, mixed>
     */
    public function receive(): array;

    /**
     * Called once the initialize handshake has settled the protocol
     * version every later message is sent under.
     */
    public function setProtocolVersion(string $version): void;

    public function disconnect(): void;
}
