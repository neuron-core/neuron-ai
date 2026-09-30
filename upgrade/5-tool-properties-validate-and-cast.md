# Upgrade: Tool properties validate and cast inputs

## Summary

In 4.x, `Tool::setInputs()` runs every argument the model sent through its property's `cast()` before anything reads it. Values are converted to the declared type (`"5"` becomes `5` for an `INTEGER` property), and an `ObjectProperty` mapped to a class is deserialized into that class. Arguments that fail validation no longer throw. `execute()` skips `__invoke()` and stores `ToolOutput::error('Parameter "<name>" ...')` as the tool result, which the model sees and can correct. `toolErrorHandler` is not called for these failures.

| | 3.x | 4.x |
|---|---|---|
| Required argument missing | `execute()` throws `MissingCallbackParameter` | error result `Parameter "query" is required.` |
| Value that cannot be converted (`"abc"` for `INTEGER`) | `TypeError` from `__invoke()` | error result |
| Convertible value (`"5"` for `INTEGER`, `"true"` for `BOOLEAN`) | passed as sent (`TypeError` on a typed parameter) | converted, then passed |
| Value outside a `ToolProperty` `enum` | passed as sent | error result |
| `null` for a required property | passed as `null` | error result, unless the property is declared `nullable: true` |
| Optional argument omitted | `__invoke()` receives `null`, even with `?int $limit = 10` | `__invoke()` receives the declared default |
| `getInput()` / `getInputs()` / `$this->inputs` on the tool | raw decoded JSON | converted values; class-mapped `ObjectProperty` values are objects |
| `ToolPropertyInterface` | 5 methods | also requires `isNullable()` and `cast()` |
| `ObjectProperty` protected hooks | `createObjectProperty()`, `createArrayProperty()`, `createScalarProperty()` | `createObjectPropertySchema()`, `createArrayPropertySchema()`, `createScalarProperty()`, each with a trailing `bool $nullable = false` |
| `ArrayProperty` `maxItems: 0` | left out of the schema (no limit) | sent as `"maxItems": 0` (the array must be empty) |
| `PropertyType::fromSchema('unknown')` | `ValueError` | `NeuronAI\Exceptions\ToolException` |

`ToolProperty`, `ArrayProperty` and `ObjectProperty` take a new last constructor argument, `nullable: bool = false`.

Stored data: nothing to migrate. Casting happens on the live tool when it executes, and tool calls recorded in chat history keep the model's raw arguments.

Owned elsewhere: guide 3 migrates tool classes (`properties()`, `__invoke()`). Guide 4 migrates `ToolCall` records read from messages and the `toolErrorHandler` callback. A `ToolCall`'s inputs stay raw. Guide 28 migrates callbacks passed to the 3.x `ToolApproval` middleware. Guide 50 migrates `McpConnector` subclasses that override `createArrayProperty()`/`createObjectProperty()`, so skip them here.

## What to Search For

Run from the application root:

```bash
# 1. Exceptions that bad arguments used to throw (Case 1)
grep -rnE 'MissingCallbackParameter|TypeError' --include='*.php' --exclude-dir=vendor .
# 2. Property declarations (Cases 2, 3, 4, 8)
grep -rnE '(ToolProperty|ArrayProperty|ObjectProperty)(::make)?\(' --include='*.php' --exclude-dir=vendor .
# 3. maxItems: 0 (Case 8)
grep -rnE 'maxItems:[[:space:]]*0([^0-9]|$)' --include='*.php' --exclude-dir=vendor .
# 4. Code that reads a tool's inputs (Case 5)
grep -rnE 'getInputs?\(|\$this->inputs([^A-Za-z0-9_]|$)|function setInputs\(' --include='*.php' --exclude-dir=vendor .
# 5. Custom property classes (Case 6)
grep -rnE 'implements[^{]*ToolPropertyInterface|extends[[:space:]]+[A-Za-z\\]*(ToolProperty|ArrayProperty|ObjectProperty)([^A-Za-z]|$)' --include='*.php' --exclude-dir=vendor .
# 6. ObjectProperty hook overrides and calls (Case 7)
grep -rnE 'create(Object|Array|Scalar)Property\(' --include='*.php' --exclude-dir=vendor .
# 7. PropertyType::fromSchema() callers (Case 9)
grep -rnE 'PropertyType::fromSchema\(' --include='*.php' --exclude-dir=vendor .
```

How to follow the hits:

- Search 1: keep only hits in tool tests, in `toolErrorHandler` callbacks or `resolveToolErrorHandler()` overrides, and in `catch` blocks around agent runs. Ignore other `TypeError` uses.
- Search 2: open every tool class with a hit and compare its property declarations with its `__invoke()` signature (Cases 2, 3 and 4). Check `ArrayProperty` calls for a positional 6th argument `0` (Case 8).
- Search 4: keep only reads on a `Tool` instance, meaning code inside tool classes or tests that call `setInputs()` and then read the inputs. Ignore reads on `ToolCall` objects and unrelated `getInput()` methods, such as evaluation results.
- Search 5: also follow subclasses of the classes found.
- Search 6: skip classes that extend `McpConnector` (guide 50).

If none of the searches finds anything, this guide does not apply.

## How to Refactor

### Case 1: Tests and handlers that expected an exception for bad arguments

Before (3.x):

```php
use NeuronAI\Exceptions\MissingCallbackParameter;

public function test_query_is_required(): void
{
    $this->expectException(MissingCallbackParameter::class);

    $tool = new SearchTool();
    $tool->setInputs([]);
    $tool->execute();
}
```

After (4.x):

```php
use NeuronAI\Tools\ToolOutput;

public function test_query_is_required(): void
{
    $tool = new SearchTool();
    $tool->setInputs([]);
    $tool->execute();

    $result = $tool->getResult();
    $this->assertInstanceOf(ToolOutput::class, $result);
    $this->assertTrue($result->isError());
    $this->assertSame('Parameter "query" is required.', $result->getText());
}
```

1. Rewrite every `expectException(MissingCallbackParameter::class)` or `expectException(TypeError::class)` around `setInputs([...])` + `execute()` as shown. A wrong type reads `Parameter "limit" must be of type integer, string given.`.
2. A test that passed a convertible value (`'5'` for an `INTEGER` property) and expected a `TypeError` now runs the tool with the converted value. Assert on the result instead.
3. A test that calls `execute()` without any `setInputs()` call still gets `MissingCallbackParameter`. Leave it unchanged.
4. Agent-level tests that expected the run to throw on bad arguments: the run now continues. The error becomes the tool result and the agent calls the provider again, so a `FakeAIProvider` needs one more queued response. Assert on the tool result instead (guide 4 shows how to read it from messages).
5. In `toolErrorHandler` callbacks, `resolveToolErrorHandler()` overrides and `catch` blocks around agent runs, delete branches for `MissingCallbackParameter`. Also delete `TypeError` branches that only handled bad model arguments, because those arguments no longer throw. Keep branches for exceptions thrown by the tool's own code. If a deleted branch sent a custom message to the model, tell the developer that the model now receives Neuron's `Parameter "<name>" ...` message instead.
6. Remove imports that became unused.

### Case 2: Required properties that must accept null

In 3.x, a `null` sent for a required property reached `__invoke()`. In 4.x it is rejected unless the property is declared `nullable: true`, which also sends `"type": ["string", "null"]` to the model.

Before (3.x):

```php
protected function properties(): array
{
    return [
        new ToolProperty('customer_id', PropertyType::STRING, 'The customer ID, or null for a guest checkout.', true),
    ];
}

public function __invoke(?string $customer_id): string
{
    return $customer_id === null ? 'Guest checkout started.' : "Checkout started for {$customer_id}.";
}
```

After (4.x):

```php
protected function properties(): array
{
    return [
        new ToolProperty('customer_id', PropertyType::STRING, 'The customer ID, or null for a guest checkout.', true, nullable: true),
    ];
}
```

Add `nullable: true` to a required property only when both of these hold:

- It is required: `required: true`, or `true` as the 4th positional argument of `ToolProperty` or the 3rd of `ArrayProperty`/`ObjectProperty`.
- Its `__invoke()` parameter accepts null (`?string`, `string|null`, `mixed`, untyped) and the body gives null a meaning, for example a null check or passing it on as "no value".

A parameter that is nullable only defensively needs no change, because 4.x asks the model for a real value. If you cannot tell which applies, ask the developer whether the model may send null for that argument.

### Case 3: Enum lists

4.x compares the converted value with the `enum` list using strict equality. Every enum value must therefore have the PHP type that matches the property type:

- strings for `STRING`
- ints for `INTEGER`
- for `NUMBER`: ints for whole values (`1`, not `1.0`) and floats otherwise

If the types don't match, every call is rejected (`Parameter "priority" must be one of "1", "2", "3"; 1 given.`).

Before (3.x):

```php
new ToolProperty('priority', PropertyType::INTEGER, 'Ticket priority', true, ['1', '2', '3']),
```

After (4.x):

```php
new ToolProperty('priority', PropertyType::INTEGER, 'Ticket priority', true, [1, 2, 3]),
```

1. Fix the value types in every `enum:` list, which is also the 5th positional argument of `ToolProperty`. This includes `items:` properties of `ArrayProperty` and fields of inline `ObjectProperty` definitions.
2. The tool body may handle values outside the enum, for example by normalizing case, mapping synonyms or using a fallback branch. If it does, ask the developer which to do:
   - keep the enum strict: the model is asked to retry, and the fallback becomes dead code
   - widen or remove the enum

### Case 4: `__invoke()` defaults now apply

3.x passed `null` for every omitted argument and ignored the parameter's default. 4.x lets PHP apply the default. It also applies the default when the model sends `null` for a parameter that cannot take null.

Before (3.x): an omitted `limit` arrived as `null`, so `searchAll()` ran.

```php
public function __invoke(string $query, ?int $limit = 10): string
{
    if ($limit === null) {
        return $this->searchAll($query);
    }

    return $this->search($query, $limit);
}
```

After (4.x):

```php
public function __invoke(string $query, ?int $limit = null): string
{
    if ($limit === null) {
        return $this->searchAll($query);
    }

    return $this->search($query, $limit);
}
```

Change a non-null default to `null` only when the body gives `null` its own meaning, for example a null check or `??` with a different fallback. Leave the signature unchanged when the body never told `null` apart from the default, or when a `null` would have crashed it: 4.x now applies the default.

### Case 5: Code that reads a tool's inputs

After `setInputs()`, `getInput()`, `getInputs()` and `$this->inputs` hold the converted values:

- numbers and booleans have their declared type
- a class-mapped `ObjectProperty` value is an instance of that class, including when it is an `ArrayProperty` item

Before (3.x):

```php
protected function properties(): array
{
    return [
        new ObjectProperty('address', 'Destination address', true, Address::class),
    ];
}

public function getRunKey(): string
{
    return $this->getName() . ':' . $this->getInput('address')['city'];
}
```

After (4.x):

```php
public function getRunKey(): string
{
    $address = $this->getInput('address');

    // Arguments rejected by validation stay raw arrays, and their run is counted too
    return $this->getName() . ':' . ($address instanceof Address ? $address->city : '');
}
```

1. Array access on a class-mapped value becomes property access (`['city']` becomes `->city`). Without this change PHP fails with `Cannot use object of type Address as array`. `getRunKey()` also runs for calls whose arguments were rejected, and those calls keep the raw arrays, so guard with `instanceof` there as shown. Code in `__invoke()` and `approvalPolicy()` never sees rejected arguments.
2. Strict comparisons with the raw spelling must compare with the typed value: `=== 'true'` becomes `=== true`, and `=== '5'` becomes `=== 5`.
3. Remove manual casts and `Deserializer` calls that repeat what the property type already declares.
4. A `setInputs()` override that normalizes arguments must end with `return parent::setInputs($inputs);`, otherwise validation and casting are skipped. Remove any normalization that the property types now do.

### Case 6: Custom `ToolPropertyInterface` implementations

Before (3.x): a class such as `DateProperty implements ToolPropertyInterface` defines `getName()`, `getType()`, `getDescription()`, `isRequired()`, `getJsonSchema()` and `jsonSerialize()`. It fatals on 4.x because two abstract methods are missing.

After (4.x): add the two methods.

```php
use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Tools\ToolPropertyInterface;

class DateProperty implements ToolPropertyInterface
{
    // getName(), getType(), getDescription(), isRequired(), getJsonSchema() and jsonSerialize() stay unchanged

    public function isNullable(): bool
    {
        return false;
    }

    public function cast(mixed $input): mixed
    {
        if ($input === null) {
            return null;
        }

        if (!is_string($input)) {
            throw new InvalidToolInput('must be of type string, ' . get_debug_type($input) . ' given');
        }

        return $input;
    }
}
```

1. `isNullable()` returns `true` only if the property's JSON schema allows `null`.
2. `cast()` returns `null` unchanged and returns the value `__invoke()` should receive: the input, converted to the property's type when needed.
3. `cast()` throws `InvalidToolInput` for input it cannot use. The model sees the message prefixed with `Parameter "<name>" `. Any other exception escapes `setInputs()`, which the agent calls before its tool error handling, so it aborts the run even when a `toolErrorHandler` is set: turn every failure caused by the input (for example a date parser's exception) into `InvalidToolInput`.
4. Classes that extend `ToolProperty`, `ArrayProperty` or `ObjectProperty` inherit both methods. Make sure they don't declare their own members with these names:
   - `isNullable()`, `cast()` or `$nullable`
   - `quote()` (from `ToolProperty`)
   - `castFields()` or `deserialize()` (from `ObjectProperty`)

   If one does, rename the application's member.

### Case 7: `ObjectProperty` subclasses that override the schema hooks

4.x never calls the old hook names, so those overrides silently stop working. A `createScalarProperty()` override without the new parameter causes a fatal incompatible-declaration error.

Before (3.x):

```php
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class DescribedObjectProperty extends ObjectProperty
{
    protected function createObjectProperty(string $name, array $propertyData, bool $required, ?string $description): ObjectProperty
    {
        return parent::createObjectProperty($name, $propertyData, $required, $description ?? "The {$name} object.");
    }

    protected function createArrayProperty(string $name, array $propertyData, bool $required, ?string $description): ArrayProperty
    {
        return parent::createArrayProperty($name, $propertyData, $required, $description ?? "The {$name} list.");
    }

    protected function createScalarProperty(string $name, array $propertyData, bool $required, ?string $description): ToolProperty
    {
        return new ToolProperty(
            $name,
            PropertyType::fromSchema($propertyData['type']),
            $description ?? "The {$name} value.",
            $required,
            $propertyData['enum'] ?? [],
        );
    }
}
```

After (4.x):

```php
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class DescribedObjectProperty extends ObjectProperty
{
    protected function createObjectPropertySchema(string $name, array $propertyData, bool $required, ?string $description, bool $nullable = false): ObjectProperty
    {
        return parent::createObjectPropertySchema($name, $propertyData, $required, $description ?? "The {$name} object.", $nullable);
    }

    protected function createArrayPropertySchema(string $name, array $propertyData, bool $required, ?string $description, bool $nullable = false): ArrayProperty
    {
        return parent::createArrayPropertySchema($name, $propertyData, $required, $description ?? "The {$name} list.", $nullable);
    }

    protected function createScalarProperty(string $name, array $propertyData, bool $required, ?string $description, bool $nullable = false): ToolProperty
    {
        return new ToolProperty(
            $name,
            PropertyType::fromSchema($propertyData['type']),
            $description ?? "The {$name} value.",
            $required,
            $propertyData['enum'] ?? [],
            $nullable,
        );
    }
}
```

1. Rename `createObjectProperty` to `createObjectPropertySchema` and `createArrayProperty` to `createArrayPropertySchema`, in both the definitions and the `parent::` calls.
2. Add the trailing `bool $nullable = false` to those two hooks and to `createScalarProperty`. Pass it on, either to the `parent::` call or as the `nullable` argument of the property you build.
3. `$propertyData['type']` can now be an array such as `['integer', 'null']`. Pass it to `PropertyType::fromSchema()`, which accepts both forms, and never to `PropertyType::from()`.

### Case 8: `ArrayProperty` with `maxItems: 0`

3.x left `maxItems: 0` out of the schema, so the array had no limit. 4.x sends it, and the model must then return an empty array.

Before (3.x):

```php
new ArrayProperty(name: 'tags', description: 'Tags to apply', maxItems: 0),
```

After (4.x):

```php
new ArrayProperty(name: 'tags', description: 'Tags to apply'),
```

For a positional call, drop the 6th argument or pass `null` for it.

### Case 9: Errors from `PropertyType::fromSchema()`

Before (3.x):

```php
use NeuronAI\Tools\PropertyType;
use ValueError;

try {
    $type = PropertyType::fromSchema($definition['type']);
} catch (ValueError) {
    $type = PropertyType::STRING;
}
```

After (4.x):

```php
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\PropertyType;

try {
    $type = PropertyType::fromSchema($definition['type']);
} catch (ToolException) {
    $type = PropertyType::STRING;
}
```

Also change tests that use `expectException(ValueError::class)` around `fromSchema()` to expect `ToolException::class`.

## Checklist

- No `expectException(MissingCallbackParameter::class)` or `expectException(TypeError::class)` remains around `setInputs()` + `execute()`. Those tests assert an error `ToolOutput`.
- No `toolErrorHandler` callback or `catch` block handles `MissingCallbackParameter`.
- Every required property whose `__invoke()` body gives `null` a meaning is declared `nullable: true`.
- Every `enum` value has the PHP type that matches its property type.
- `__invoke()` parameters whose body gives `null` its own meaning default to `null`.
- No array access remains on class-mapped `ObjectProperty` values read through `getInput()`, `getInputs()` or `$this->inputs`.
- Every class that implements `ToolPropertyInterface` directly defines `isNullable()` and `cast()`.
- Search 6 finds no `createObjectProperty(` or `createArrayProperty(` outside `McpConnector` subclasses, and the three hooks take `bool $nullable = false`.
- Search 3 finds no `maxItems: 0`.
- No `catch (ValueError)` remains around `PropertyType::fromSchema()`.
- Static analysis reports no error about `ToolPropertyInterface`, the renamed `ObjectProperty` hooks or `PropertyType::fromSchema()`.
