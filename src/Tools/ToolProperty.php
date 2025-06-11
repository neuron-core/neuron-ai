<?php

namespace NeuronAI\Tools;

class ToolProperty implements ToolPropertyInterface
{
    public function __construct(
        protected string $name,
        protected PropertyType $type,
        protected string $description,
        protected bool $required = false,
        protected array $enum = [],
        private readonly bool $asArrayItem = false,
    ) {
    }

    /**
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type->value,
            'enum' => $this->enum,
            'required' => $this->required,
        ];
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

    public function getEnum(): array
    {
        return $this->enum;
    }

    public function getJsonSchema(): array
    {
        $schema = [
            'type' => $this->type->value,
        ];

        if (!$this->asArrayItem) {
            $schema['description'] = $this->description;
        }

        if (!empty($this->enum)) {
            $schema['enum'] = $this->enum;
        }

        return $schema;
    }

    /**
     * Returns a ToolPropertyInterface representing a string item within an array.
     *
     * @return ToolPropertyInterface
     * @throws \Exception
     */
    public static function asStringItem(): ToolPropertyInterface
    {
        return self::asItem(PropertyType::STRING);
    }

    /**
     * Returns a ToolPropertyInterface representing a number item within an array.
     *
     * @return ToolPropertyInterface
     * @throws \Exception
     */
    public static function asNumberItem(): ToolPropertyInterface
    {
        return self::asItem(PropertyType::NUMBER);
    }

    /**
     * Returns a ToolPropertyInterface representing an integer item within an array.
     *
     * @return ToolPropertyInterface
     * @throws \Exception
     */
    public static function asIntegerItem(): ToolPropertyInterface
    {
        return self::asItem(PropertyType::INTEGER);
    }

    /**
     * Returns a ToolPropertyInterface representing a boolean item within an array.
     *
     * @return ToolPropertyInterface
     * @throws \Exception
     */
    public static function asBooleanItem(): ToolPropertyInterface
    {
        return self::asItem(PropertyType::BOOLEAN);
    }

    /**
     * Creates a ToolPropertyInterface representing an array item of the specified primitive type.
     *
     * This method initializes a ToolProperty configured for use as an array item,
     * where the `name` and `description` fields are intentionally left empty,
     * since they are not relevant in the context of array items.
     *
     * @param PropertyType $type The primitive property type of the array item.
     *
     * @return ToolPropertyInterface
     *
     * @throws \Exception If the provided type is not a valid primitive PropertyType.
     */
    public static function asItem(PropertyType $type): ToolPropertyInterface
    {
        if (!in_array($type, PropertyType::primitives())) {
            throw new \Exception("Invalid property type '{$type}': only primitive types are allowed.");
        }

        return new self(
            name: '',
            type: $type,
            description: '',
            asArrayItem: true,
        );
    }
}
