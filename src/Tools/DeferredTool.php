<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;

/**
 * A tool declaration whose execution belongs outside the backend.
 */
class DeferredTool extends Tool implements DeferredToolInterface
{
    /**
     * @param array<string, mixed>|null $inputSchema
     * @throws ToolException
     * @throws ArrayPropertyException
     * @throws \ReflectionException
     */
    public function __construct(
        string $name,
        ?string $description = null,
        protected ?array $inputSchema = null,
    ) {
        $this->name = $name;
        $this->description = $description;
        if ($inputSchema !== null) {
            $this->properties = ToolPropertyFactory::fromSchema($inputSchema);
        }
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema ?? parent::getInputSchema();
    }

    /** @return ToolPropertyInterface[] */
    public function getProperties(): array
    {
        return $this->inputSchema === null ? parent::getProperties() : $this->properties;
    }

    /** @throws ToolException */
    public function addProperty(ToolPropertyInterface $property): ToolInterface
    {
        if ($this->inputSchema !== null) {
            throw new ToolException(
                "Tool \"{$this->getName()}\" already has an explicit input schema: individual properties cannot be added."
            );
        }

        return parent::addProperty($property);
    }

    /**
     * @throws ToolException
     */
    final public function execute(): void
    {
        throw new ToolException(
            "Tool \"{$this->getName()}\" requires external execution: submit its result instead of executing it on the backend."
        );
    }
}
