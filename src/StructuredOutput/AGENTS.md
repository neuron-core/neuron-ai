# StructuredOutput Module

JSON schema-based extraction with PHP class mapping.

## Core

| File | Purpose |
|------|---------|
| `JsonSchema.php` | Generates JSON Schema from PHP attributes |
| `JsonExtractor.php` | Extracts and parses JSON from AI responses |
| `SchemaProperty.php` | Attribute for custom schema properties |
| `SchemaPropertiesInterface.php` | Runtime schema property definitions |

## Usage

```php
class UserProfile {
    #[SchemaProperty(description: 'User name')]
    public string $name;

    #[SchemaProperty(description: 'User age')]
    public int $age;
}

$schema = JsonSchema::make(UserProfile::class)->generate();
// Returns JSON Schema for the class
```

## Runtime Schema Properties

PHP attribute arguments must be compile-time constants, so `SchemaProperty`
cannot carry dynamic values (translated descriptions, config-driven constraints).
Implement `SchemaPropertiesInterface` to build `SchemaProperty` objects at runtime:

```php
class UserProfile implements SchemaPropertiesInterface {
    public string $name;

    #[SchemaProperty(description: 'User age')]
    public int $age;

    public static function schemaProperties(): array
    {
        return [
            'name' => new SchemaProperty(description: trans('user.name')),
        ];
    }
}
```

Resolution rules (`SchemaProperty::resolve()`, shared by `JsonSchema` and `Deserializer`):
- A property listed in `schemaProperties()` uses that object (it replaces the attribute entirely).
- A property not listed falls back to its `#[SchemaProperty]` attribute.
- Runtime definitions behave identically to attributes, including `anyOf` array hydration.

The method is static and receives no context: runtime values must come from
globally reachable state (translator helpers, config, a service locator).

## Schema Generation

Reads PHP attributes and types to generate compatible JSON Schema:
- String, int, float, bool
- Arrays and nested objects
- Optional vs required properties
- Enum support

## JSON Extraction

`JsonExtractor` handles:
- Finding JSON in mixed content
- Parsing code blocks with ```json
- Repairing malformed JSON
- Multiple JSON objects

## Validation (`Validation/`)

Post-extraction validation rules.

## Deserializer (`Deserializer/`)

Maps JSON to PHP objects.

## Dependencies

- `Chat` module for message types
