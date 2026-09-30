# Upgrade: Structured output extension points and #[Url] schemes

## Summary

Two changes in `NeuronAI\StructuredOutput` affect application code:

1. **Protected methods of `JsonSchema` and `Deserializer` changed signature.** An application subclass that overrides one of them with its 3.x signature fails when the class loads ("Declaration of ... must be compatible with ..."), except for `handleEnum()` (Case 3). `Deserializer::findPropertyValue()` is removed. An override of it still loads, but 4.x never calls it. Its replacement is `findPropertyKey()`, which returns the matching key instead of the value.
2. **`#[Url]` accepts only `http` and `https` by default.** In 3.x it accepted any scheme PHP's URL filter accepts (`ftp:`, `mailto:`, `s3:`, custom app schemes). A structured output property whose value uses another scheme now fails validation, so the agent retries and finally throws. The rule has a new constructor parameter, `schemes`.

| Class | 3.x | 4.x |
|---|---|---|
| `JsonSchema` | `processProperty(ReflectionProperty $property): array` | `processProperty(ReflectionClass $class, ReflectionProperty $property): array` |
| `JsonSchema` | `getPropertyAttribute(ReflectionProperty $property): ?SchemaProperty` | `getPropertyAttribute(ReflectionClass $class, ReflectionProperty $property): ?SchemaProperty` |
| `Deserializer` | `findPropertyValue(array $data, string $propertyName): mixed` | removed: `findPropertyKey(array $data, string $propertyName): ?string` |
| `Deserializer` | `castValue(mixed $value, ReflectionType $type, ReflectionProperty $property): mixed` | `castValue(mixed $value, ReflectionType $type, ReflectionClass $class, ReflectionProperty $property): mixed` |
| `Deserializer` | `castToSingleType(mixed $value, ReflectionNamedType $type, ReflectionProperty $property): mixed` | `castToSingleType(mixed $value, ReflectionNamedType $type, ReflectionClass $class, ReflectionProperty $property): mixed` |
| `Deserializer` | `handleSingleObject(mixed $value, string $typeName): mixed` | `handleSingleObject(mixed $value, string $typeName, ReflectionProperty $property): mixed` |
| `Deserializer` | `handleArray(mixed $value, ReflectionProperty $property): mixed` | `handleArray(mixed $value, ReflectionClass $class, ReflectionProperty $property): array` |
| `Deserializer` | `handleEnum(BackedEnum\|string $typeName, mixed $value): BackedEnum` | `handleEnum(string $typeName, mixed $value): UnitEnum` |
| `Validation\Rules\Url` | `#[Url]`: any scheme | `#[Url]`: `http`, `https`; `#[Url(schemes: [...])]` for others |

All other protected methods keep their 3.x signatures. The public API (`JsonSchema::make()->generate()`, `Deserializer::make()->fromJson()`, `Validator::validate()`) is unchanged. `$class` is the `ReflectionClass` of the class being described or deserialized.

No data stored with 3.x is involved: this guide changes PHP code only.

## What to Search For

Run from the application root:

```bash
# 1. Subclasses of JsonSchema or Deserializer, their imports (to catch aliases) and findPropertyValue()
grep -rnE 'NeuronAI\\StructuredOutput\\(JsonSchema|Deserializer\\Deserializer)([^A-Za-z0-9_\\]|$)|extends +\\?([A-Za-z0-9_]+\\)*(JsonSchema|Deserializer)([^A-Za-z0-9_]|$)|findPropertyValue' --include='*.php' --exclude-dir=vendor .

# 2. The Url rule: imports, attributes, direct constructions and subclasses
grep -rnE 'Validation\\Rules\\(Url([^A-Za-z0-9_\\]|$)|\{[^}]*Url)|(^|[^A-Za-z0-9_$>:])Url *(\(|\]|,)|extends +\\?([A-Za-z0-9_]+\\)*Url([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .

# 3. Member names 4.x added to JsonSchema, Deserializer and Url (only hits inside a subclass found above matter)
grep -rnE 'function +(typeName|schemaValue|applyDefaults|unionMembers|castScalar|deserializeItem|typeMismatch|hasAcceptedScheme) *\(|\$schemes([^A-Za-z0-9_]|$)' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:

- A file that only calls `JsonSchema::make()->generate()` or `Deserializer::make()->fromJson()` needs no change.
- For each import hit, check whether the file declares a class that extends the imported name, including under an alias (`use ...\JsonSchema as BaseSchema;` then `extends BaseSchema`).
- For each subclass found, also search for classes that extend it (`grep -rn 'extends LabelledJsonSchema'`), and apply the same cases to them.
- In each subclass, list the methods it declares and every `$this->...()` or `parent::...()` call to a method in the table: overrides and calls both need the 4.x arguments.
- For each `Url` subclass, search for where it is used as an attribute (`grep -rn 'DocsUrl'`), and apply Case 5 to those properties too.
- A hit on `Url` that is not `NeuronAI\StructuredOutput\Validation\Rules\Url` (another library's class, or text in a comment) does not apply.

If patterns 1 and 2 find nothing, this guide does not apply.

## How to Refactor

### Case 1: A `JsonSchema` subclass overrides or calls `processProperty()` or `getPropertyAttribute()`

**Before (3.x):**

```php
use App\StructuredOutput\Label;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use ReflectionProperty;

class LabelledJsonSchema extends JsonSchema
{
    protected function processProperty(ReflectionProperty $property): array
    {
        $schema = parent::processProperty($property);
        $schema['title'] ??= ucfirst(str_replace('_', ' ', $property->getName()));

        return $schema;
    }

    protected function getPropertyAttribute(ReflectionProperty $property): ?SchemaProperty
    {
        $labels = $property->getAttributes(Label::class);

        if ($labels !== []) {
            return new SchemaProperty(description: $labels[0]->newInstance()->text);
        }

        return parent::getPropertyAttribute($property);
    }
}
```

**After (4.x):**

```php
use App\StructuredOutput\Label;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use ReflectionClass;
use ReflectionProperty;

class LabelledJsonSchema extends JsonSchema
{
    protected function processProperty(ReflectionClass $class, ReflectionProperty $property): array
    {
        $schema = parent::processProperty($class, $property);
        $schema['title'] ??= ucfirst(str_replace('_', ' ', $property->getName()));

        return $schema;
    }

    protected function getPropertyAttribute(ReflectionClass $class, ReflectionProperty $property): ?SchemaProperty
    {
        $labels = $property->getAttributes(Label::class);

        if ($labels !== []) {
            return new SchemaProperty(description: $labels[0]->newInstance()->text);
        }

        return parent::getPropertyAttribute($class, $property);
    }
}
```

1. Add `ReflectionClass $class` as the first parameter of each override, and pass it on to `parent::`. Add `use ReflectionClass;`.
2. Where the subclass calls these methods from its own code (for example in an overridden `generateClassSchema()`), pass the `ReflectionClass` of the class whose properties it is iterating (`new ReflectionClass($class)` in `generateClassSchema()`). Do not pass `$property->getDeclaringClass()`: for an inherited property, that is the parent class, not the class being described.
3. If a `getPropertyAttribute()` override returns a `SchemaProperty` with `required` set, that value no longer counts. 4.x builds the schema's `required` list from `SchemaProperty::isRequired($class, $property)`, which reads the `#[SchemaProperty]` attribute itself and never calls this override. The Deserializer uses the same check. The override still sets title, description, limits and `anyOf` in the property schema. If the `required` value matters, tell the developer. The 4.x way to set it at runtime is to implement `NeuronAI\StructuredOutput\SchemaPropertiesInterface` on the output class: its `public static function schemaProperties(): array` returns `SchemaProperty` objects keyed by property name, and JsonSchema, Deserializer and Validator all read them. Ask the developer before adding it to the output classes.

### Case 2: A `Deserializer` subclass overrides or calls `findPropertyValue()`

4.x never calls `findPropertyValue()`, and PHPStan reports an override only when it calls `parent::findPropertyValue()`. Rename the method to `findPropertyKey()` and return the matching key instead of the value.

**Before (3.x):**

```php
use NeuronAI\StructuredOutput\Deserializer\Deserializer;

class KebabCaseDeserializer extends Deserializer
{
    protected function findPropertyValue(array $data, string $propertyName): mixed
    {
        $kebabCase = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $propertyName));

        if (array_key_exists($kebabCase, $data)) {
            return $data[$kebabCase];
        }

        return parent::findPropertyValue($data, $propertyName);
    }
}
```

**After (4.x):**

```php
use NeuronAI\StructuredOutput\Deserializer\Deserializer;

class KebabCaseDeserializer extends Deserializer
{
    protected function findPropertyKey(array $data, string $propertyName): ?string
    {
        $kebabCase = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $propertyName));

        if (array_key_exists($kebabCase, $data)) {
            return $kebabCase;
        }

        return parent::findPropertyKey($data, $propertyName);
    }
}
```

1. Return a key that exists in `$data`, or `null` when nothing matches. The caller reads `$data[$key]` itself.
2. If the 3.x override changed the value instead of only choosing a key (trimming, unwrapping, mapping), move that change into an override of `deserializeObject(array $data, string $className): object`, whose signature did not change. Rewrite `$data` there, then return `parent::deserializeObject($data, $className)`. It runs for the top-level object and for every nested object.
3. Replace calls in the subclass's own code:

   ```php
   // 3.x
   $value = $this->findPropertyValue($data, $name);

   // 4.x
   $key = $this->findPropertyKey($data, $name);
   $value = $key === null ? null : $data[$key];
   ```

### Case 3: A `Deserializer` subclass overrides or calls the casting methods

This case covers `castValue()`, `castToSingleType()`, `handleSingleObject()`, `handleArray()` and `handleEnum()`.

**Before (3.x):**

```php
use DateInterval;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use ReflectionNamedType;
use ReflectionProperty;

class IntervalDeserializer extends Deserializer
{
    protected function castToSingleType(mixed $value, ReflectionNamedType $type, ReflectionProperty $property): mixed
    {
        if ($type->getName() === DateInterval::class && is_string($value)) {
            return new DateInterval($value);
        }

        return parent::castToSingleType($value, $type, $property);
    }

    protected function handleArray(mixed $value, ReflectionProperty $property): mixed
    {
        // The model sometimes sends a list of tags as one comma-separated string
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }

        return parent::handleArray($value, $property);
    }
}
```

**After (4.x):**

```php
use DateInterval;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

class IntervalDeserializer extends Deserializer
{
    protected function castToSingleType(mixed $value, ReflectionNamedType $type, ReflectionClass $class, ReflectionProperty $property): mixed
    {
        if ($type->getName() === DateInterval::class && is_string($value)) {
            return new DateInterval($value);
        }

        return parent::castToSingleType($value, $type, $class, $property);
    }

    protected function handleArray(mixed $value, ReflectionClass $class, ReflectionProperty $property): array
    {
        // The model sometimes sends a list of tags as one comma-separated string
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }

        return parent::handleArray($value, $class, $property);
    }
}
```

1. Give each override the exact 4.x parameter list from the Summary table, and forward the new arguments to `parent::`. `castValue()`, `castToSingleType()` and `handleArray()` take `ReflectionClass $class` before `ReflectionProperty $property`. `handleSingleObject()` takes `ReflectionProperty $property` as its new third parameter. Add `use ReflectionClass;` where needed.
2. `handleArray()` must declare `: array`. If the 3.x body could return something other than an array, throw `NeuronAI\StructuredOutput\Deserializer\DeserializerException` there instead. 4.x's own `handleArray()` rejects non-arrays that way, and structured output retries on that exception.
3. `handleEnum()`: the 3.x declaration still loads. 4.x also deserializes enums without a backing type (by case name), so `parent::handleEnum()` can return a case that a `BackedEnum` return type rejects with a `TypeError`. Declare it as `handleEnum(string $typeName, mixed $value): UnitEnum`.
4. Where the subclass calls these methods from its own code (for example in an overridden `deserializeObject()`), pass the `ReflectionClass` of the class being deserialized (the `new ReflectionClass($className)` built there), and the property for `handleSingleObject()`:

   ```php
   // 3.x
   $value = $this->castValue($value, $type, $property);

   // 4.x
   $value = $this->castValue($value, $type, $reflection, $property);
   ```

### Case 4: A subclass declares a member name that 4.x now uses

4.x added these protected members:

- `JsonSchema`: `typeName()`, `schemaValue()`
- `Deserializer`: `applyDefaults()`, `unionMembers()`, `castScalar()`, `deserializeItem()`, `typeMismatch()`
- `Url`: `hasAcceptedScheme()`, property `$schemes`

A subclass member with one of these names either fails to load (incompatible signature or visibility) or silently replaces the framework's own member. For each hit of pattern 3 that declares such a method or property inside a subclass found by patterns 1 or 2, rename the application's member (for example `typeName()` to `resolveTypeName()`) and update its call sites in the subclass and its children. A local variable or a constructor parameter named `$schemes` that is only passed to `parent::__construct()` is not a collision.

### Case 5: A `#[Url]` property may hold URLs that are not http or https

**Before (3.x):**

```php
use NeuronAI\StructuredOutput\Validation\Rules\Url;

class Attachment
{
    #[Url]
    public string $downloadUrl;
}
```

**After (4.x):**

```php
use NeuronAI\StructuredOutput\Validation\Rules\Url;

class Attachment
{
    #[Url(schemes: ['http', 'https', 'ftp'])]
    public string $downloadUrl;
}
```

1. For each `#[Url]` (and each `new Url(`), look for evidence of the schemes its values use: the property name and its `#[SchemaProperty]` description, the agent's instructions, test fixtures and sample data, and what the application does with the value (an FTP or S3 client, `mailto:` links, deep links into a mobile app).
2. If the evidence shows other schemes, list every accepted scheme, including `http` and `https` when they stay valid.
3. If there is no such evidence, leave `#[Url]` as it is.
4. If you cannot tell, ask the developer: "Which URL schemes must `<Class>::$<property>` accept? In 4.x, `#[Url]` accepts only http and https by default. In 3.x it accepted any scheme, for example ftp:, mailto:, s3: or custom app schemes."
5. Do not add `javascript`, `file` or `data` unless the developer confirms: the 4.x default rejects them on purpose.
6. If a test asserts the Url violation text, update it: the message is now `{name} must be a valid URL ({schemes})`, for example `website must be a valid URL (http, https)`.

### Case 6: A subclass of `Url` declares its own constructor

`Url` now has a constructor that sets `$schemes`. A subclass constructor that does not call it leaves the property uninitialized, and validation fails with an `Error` ("Typed property ...Url::$schemes must not be accessed before initialization"). PHPStan does not report this.

**Before (3.x):**

```php
use Attribute;
use NeuronAI\StructuredOutput\Validation\Rules\Url;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DocsUrl extends Url
{
    public function __construct(string $message = '{name} must link to the documentation')
    {
        $this->message = $message;
    }
}
```

**After (4.x):**

```php
use Attribute;
use NeuronAI\StructuredOutput\Validation\Rules\Url;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DocsUrl extends Url
{
    public function __construct(string $message = '{name} must link to the documentation')
    {
        parent::__construct();
        $this->message = $message;
    }
}
```

`parent::__construct()` accepts http and https. If the subclass's values may use other schemes, decide as in Case 5 and pass them: `parent::__construct(['http', 'https', 'ftp'])`.

## Checklist

- No subclass of `JsonSchema` or `Deserializer`, including subclasses of those subclasses, declares a method from the Summary table with its 3.x signature.
- No application code declares or calls `findPropertyValue()`. Every `findPropertyKey()` override returns a key present in `$data`, or `null`.
- Every `handleArray()` override declares `: array`, and every `handleEnum()` override declares `: UnitEnum`.
- Calls from subclass code to these methods pass the class being described or deserialized, not `$property->getDeclaringClass()`.
- No subclass declares `typeName()`, `schemaValue()`, `applyDefaults()`, `unionMembers()`, `castScalar()`, `deserializeItem()`, `typeMismatch()`, `hasAcceptedScheme()` or `$schemes` for its own purposes.
- The developer was told about any `getPropertyAttribute()` override whose `required` value 4.x now ignores.
- Every `#[Url]` whose values may use schemes other than http or https lists them in `schemes:`. The developer was asked where this was unclear.
- Every `Url` subclass constructor calls `parent::__construct()`.
- Tests asserting the Url violation text expect `... must be a valid URL (<schemes>)`.
- PHPStan reports no error about `JsonSchema`, `Deserializer` or `Url`.
