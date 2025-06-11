<?php

namespace NeuronAI\Tools;

class ObjectProperty implements ToolPropertyInterface
{
    protected PropertyType $type = PropertyType::OBJECT;

    /**
     * @param string $name The name of the property.
     * @param string $description A description explaining the purpose or usage of the property.
     * @param bool $required Whether the property is required (true) or optional (false). Defaults to false.
     * @param string|null $class The associated class name, or null if not applicable.
     * @param array<ToolPropertyInterface> $properties An array of additional properties.
     * @throws \ReflectionException
     */
    public function __construct(
        protected string      $name,
        protected string      $description,
        protected bool        $required = false,
        protected ?string     $class = null,
        protected array       $properties = [],
        private readonly bool $asArrayItem = false,
    ) {
        // If both are provided, explicitly set properties take precedence over the given class.
        if (empty($this->properties) && class_exists($this->class)) {

            // Load the object properties from the given class
            $this->properties = (new PropertyLoader($this->class))->load();
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'properties' => $this->getJsonSchema(),
            'required' => $this->required,
        ];
    }

    // Required properties from the mapped class or the explicitly specified required properties
    public function getRequiredProperties(): array
    {
        return  array_values(\array_filter(\array_map(function (ToolPropertyInterface $property) {
            return $property->isRequired() ? $property->getName() : null;
        }, $this->properties)));
    }

    public function getJsonSchema(): array
    {
        $schema = [
            'type' => $this->type->value,
        ];

        // This field is irrelevant in the context of an array items definition
        if (!$this->asArrayItem) {
            $schema['description'] = $this->description;
        }

        $properties = \array_reduce($this->properties, function (array $carry, ToolPropertyInterface $property) {
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

    public function getDescription(): string
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

    /**
     * Creates a ToolPropertyInterface instance representing an item within an array.
     *
     * This method initializes an ObjectProperty configured for use as an array item,
     * where the `name` and `description` fields are intentionally left empty,
     * since they are not serialized in the context of array items.
     *
     * @param string $class The fully qualified class name that this item represents.
     *
     * @return ToolPropertyInterface An instance representing the array item property.
     *
     * @throws \ReflectionException If there is an error during reflection operations inside ObjectProperty.
     */
    public static function asItem(string $class): ToolPropertyInterface
    {
        // Fields name and description are not serialized in the context of an array items definition
        return new ObjectProperty(
            name: '',
            description: '',
            class: $class,
            asArrayItem: true,
        );
    }
}
