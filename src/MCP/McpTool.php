<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use NeuronAI\Tools\Tool;

class McpTool extends Tool
{
    public function __construct(
        string $name,
        ?string $description,
        array $annotations,
        protected McpConnector $connector,
        protected array $item,
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->annotations = $annotations;
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return $this->connector->invokeTool(
            item: $this->item,
            arguments: $arguments,
        );
    }
}
