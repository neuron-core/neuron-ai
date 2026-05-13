<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

/**
 * @method static static make(string $name, ?string $description = null, array $properties = [], array $parameters = [], array $annotations = [])
 */
class ToolDefinition extends Tool
{
    public function __construct(
        protected string $name,
        protected ?string $description = null,
        protected array $properties = [],
        protected array $parameters = [],
        protected array $annotations = [],
    ) {
    }

    public function __invoke(mixed ...$arguments): mixed
    {
        return null;
    }
}
