# StructuredOutput Module

JSON schema-based extraction with PHP class mapping.

## Core

| File | Purpose |
|------|---------|
| `JsonSchema.php` | Generates JSON Schema from PHP attributes |
| `JsonExtractor.php` | Extracts and parses JSON from AI responses |
| `SchemaProperty.php` | Attribute for custom schema properties |

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
