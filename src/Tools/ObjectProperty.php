<?php

declare(strict_types=1);

namespace NeuronAI\Tools;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\StaticConstructor;
use NeuronAI\StructuredOutput\JsonSchema;

/**
 * @method static static make(string $name, string $description, bool $required = false, ?string $class = null, array $properties = [])
 */
class ObjectProperty implements ToolPropertyInterface
{
    use StaticConstructor;

    protected PropertyType $type = PropertyType::OBJECT;

    /**
     * @param string|null $class The associated class name, or null if not applicable.
     * @param ToolPropertyInterface[] $properties An array of additional properties.
     * @throws \ReflectionException
     * @throws ToolException
     * @throws ArrayPropertyException
     */
    public function __construct(
        protected string $name,
        protected ?string $description = null,
        protected bool $required = false,
        protected ?string $class = null,
        protected array $properties = [],
    ) {
        if ($this->properties === [] && !\is_null($this->class) && \class_exists($this->class)) {
            $schema = (new JsonSchema())->generate($this->class);
            $this->properties = $this->buildPropertiesFromClass($schema);
        }
    }

    /**
     * Recursively build properties from a class schema
     *
     * @return ToolPropertyInterface[]
     * @throws \ReflectionException
     * @throws ToolException
     * @throws ArrayPropertyException
     */
    protected function buildPropertiesFromClass(array $schema): array
    {
        $required = $schema['required'] ?? [];
        $properties = [];

        foreach ($schema['properties'] as $propertyName => $propertyData) {
            $isRequired = \in_array($propertyName, $required);
            $property = $this->createPropertyFromSchema($propertyName, $propertyData, $isRequired);

            if ($property instanceof ToolPropertyInterface) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /**
     * Create a property from schema data recursively
     *
     * @throws \ReflectionException
     * @throws ToolException
     * @throws ArrayPropertyException
     */
    protected function createPropertyFromSchema(string $propertyName, array $propertyData, bool $isRequired): ?ToolPropertyInterface
    {
        $type = $propertyData['type'] ?? 'string';
        $description = $propertyData['description'] ?? null;

        return match ($type) {
            'object' => $this->createObjectProperty($propertyName, $propertyData, $isRequired, $description),
            'array' => $this->createArrayProperty($propertyName, $propertyData, $isRequired, $description),
            'string', 'integer', 'number', 'boolean' => $this->createScalarProperty($propertyName, $propertyData, $isRequired, $description),
            default => new ToolProperty(
                $propertyName,
                PropertyType::STRING,
                $description,
                $isRequired,
                $propertyData['enum'] ?? []
            ),
        };
    }

    /**
     * Create an object property recursively
     *
     * @throws \ReflectionException
     * @throws ToolException
     * @throws ArrayPropertyException
     */
    protected function createObjectProperty(string $name, array $propertyData, bool $required, ?string $description): ObjectProperty
    {
        $nestedProperties = [];
        $nestedRequired = $propertyData['required'] ?? [];

        // If there's a class reference in the schema, use it
        $className = $propertyData['class'] ?? null;

        // If no class is specified, but we have nested properties, build them recursively
        if (!$className && isset($propertyData['properties'])) {
            foreach ($propertyData['properties'] as $nestedPropertyName => $nestedPropertyData) {
                $nestedIsRequired = \in_array($nestedPropertyName, $nestedRequired);
                $nestedProperty = $this->createPropertyFromSchema($nestedPropertyName, $nestedPropertyData, $nestedIsRequired);

                if ($nestedProperty instanceof ToolPropertyInterface) {
                    $nestedProperties[] = $nestedProperty;
                }
            }
        }

        return new ObjectProperty(
            $name,
            $description,
            $required,
            $className,
            $nestedProperties
        );
    }

    /**
     * Create an array property with recursive item handling
     *
     * @throws \ReflectionException
     * @throws ToolException
     * @throws ArrayPropertyException
     */
    protected function createArrayProperty(string $name, array $propertyData, bool $required, ?string $description): ArrayProperty
    {
        $items = null;
        $minItems = $propertyData['minItems'] ?? null;
        $maxItems = $propertyData['maxItems'] ?? null;

        // Handle array items recursively
        if (isset($propertyData['items'])) {
            $itemsData = $propertyData['items'];
            $items = $this->createPropertyFromSchema($name . '_item', $itemsData, false);
        }

        return new ArrayProperty(
            $name,
            $description,
            $required,
            $items,
            $minItems,
            $maxItems
        );
    }

    /**
     * Create a scalar property (string, integer, number, boolean)
     *
     * @throws ToolException
     */
    protected function createScalarProperty(string $name, array $propertyData, bool $required, ?string $description): ToolProperty
    {
        return new ToolProperty(
            $name,
            PropertyType::fromSchema($propertyData['type']),
            $description,
            $required,
            $propertyData['enum'] ?? []
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            ...(\is_null($this->description) ? [] : ['description' => $this->description]),
            'type' => $this->type,
            'properties' => $this->getJsonSchema(),
            'required' => $this->required,
        ];
    }

    // The mapped class required properties and required properties are merged
    public function getRequiredProperties(): array
    {
        return \array_values(\array_filter(\array_map(fn (
            ToolPropertyInterface $property
        ): ?string => $property->isRequired() ? $property->getName() : null, $this->properties)));
    }

    public function getJsonSchema(): array
    {
        $schema = [
            'type' => $this->type->value,
        ];

        if (!\is_null($this->description)) {
            $schema['description'] = $this->description;
        }

        $properties = \array_reduce($this->properties, function (array $carry, ToolPropertyInterface $property): array {
            $carry[$property->getName()] = $property->getJsonSchema();
            return $carry;
        }, []);

        if (!empty($properties)) {
            $schema['properties'] = $properties;
            $schema['required'] = $this->getRequiredProperties();
        }

        return $schema;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): PropertyType
    {
        return $this->type;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getClass(): ?string
    {
        return $this->class;
    }
}
