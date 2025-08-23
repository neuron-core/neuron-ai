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

    public function disconnect(): void;
}
