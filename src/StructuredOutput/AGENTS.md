# StructuredOutput Module

Turns a PHP class into a JSON Schema for the model, then turns the model's answer back into a validated instance of that class. The PHP class is the single source of truth: property types, nullability and attributes drive schema generation, deserialization and validation alike.

## Pipeline

`JsonSchema` (class → schema, from property types and `#[SchemaProperty]` attributes; nested objects, arrays and enums included) → provider call → `JsonExtractor` (finds the JSON inside a free-form answer: fenced blocks, bracket scanning, pluggable extractors) → `Deserializer` (JSON → object) → `Validator` (attribute-driven rules from `Validation/Rules/`).

The Agent's `StructuredOutputNode` runs this pipeline in a retry loop: a deserialization or validation failure is sent back to the model as a correction message and the call is retried up to `maxRetries`. Each attempt is a distinct provider call, so its memo is attempt-indexed and a replay recalls a succeeded attempt without re-calling the provider.

## Runtime schema properties

PHP attribute arguments must be compile-time constants, so `#[SchemaProperty]` cannot carry a translated description or a config-driven constraint. `SchemaPropertiesInterface` is the escape hatch: the class builds `SchemaProperty` objects at runtime.

```php
class UserProfile implements SchemaPropertiesInterface
{
    public string $name;

    #[SchemaProperty(description: 'User age')]
    public int $age;

    public static function schemaProperties(): array
    {
        return ['name' => new SchemaProperty(description: trans('user.name'))];
    }
}
```

`SchemaProperty::resolve()` is the one resolution point shared by `JsonSchema` and `Deserializer`: a property listed in `schemaProperties()` uses that object and ignores its attribute entirely; anything else falls back to the attribute. The method is static and receives no context, so runtime values must come from globally reachable state (translator helpers, config, a service locator).
