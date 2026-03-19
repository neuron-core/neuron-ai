---
name: neuron-structured-output
description: Design and implement structured output classes for Neuron AI agents using SchemaProperty attributes and validation rules. Use this skill when the user mentions structured output, JSON schema extraction, data validation, output classes, DTOs for AI responses, extracting structured data from LLM, or configuring property schemas. Also trigger for any task involving SchemaProperty attribute, validation rules like NotBlank/Email/Url, nested objects, arrays of objects, enums, polymorphic types with anyOf, or the Validator class.
---

# Neuron AI Structured Output

This skill helps you create structured output classes for extracting typed data from LLM responses in Neuron AI applications.

## Core Concept

Neuron AI uses a **two-layer approach** for structured output:

1. **SchemaProperty** - Controls the JSON schema sent to the LLM to guide generation
2. **Validation Rules** - Verifies the LLM response meets your requirements

```php
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Person
{
    #[SchemaProperty(description: 'The user name.', required: true)]
    #[NotBlank]
    public string $name;
}
```

## SchemaProperty Attribute

The `SchemaProperty` attribute defines how properties are represented in the JSON schema sent to the LLM.

### Constructor Arguments

```php
#[SchemaProperty(
    string $title = null,        // Property title in JSON schema
    string $description = null,  // Property description (guides LLM)
    bool $required = null,       // Override required status
    int $min = null,             // Minimum value (numbers) or items (arrays)
    int $max = null,             // Maximum value (numbers) or items (arrays)
    int $minLength = null,       // Minimum string length
    int $maxLength = null,       // Maximum string length
    array $anyOf = null,         // Array of allowed class/enum types
)]
```

### Argument Implications

| Argument | JSON Schema Output | Usage |
|----------|-------------------|-------|
| `title` | `title: "..."` | Human-readable property title |
| `description` | `description: "..."` | **Critical**: Guides LLM on what to generate |
| `required: true` | Adds to `required` array | Property must be present |
| `required: false` | Excludes from `required` | Property is optional |
| `min` | `minimum` (numbers) or `minItems` (arrays) | Lower bound constraint |
| `max` | `maximum` (numbers) or `maxItems` (arrays) | Upper bound constraint |
| `minLength` | `minLength` | Minimum string characters |
| `maxLength` | `maxLength` | Maximum string characters |
| `anyOf` | `anyOf: [...]` | Polymorphic types (multiple possible classes) |

### Required Property Logic

Properties are **required by default** unless:
- `required: false` is explicitly set in SchemaProperty
- Property is nullable (`?string $name`)
- Property has a default value (`string $name = 'default'`)

```php
class Example
{
    // Required - non-nullable, no default
    public string $firstName;

    // Optional - explicitly marked
    #[SchemaProperty(required: false)]
    public string $middleName;

    // Optional - nullable
    public ?string $lastName;

    // Optional - has default
    public string $country = 'US';
}
```

## Basic Property Types

### String Properties

```php
class Article
{
    #[SchemaProperty(
        description: 'The article title',
        minLength: 10,
        maxLength: 200
    )]
    public string $title;

    #[SchemaProperty(description: 'Article content')]
    public string $content;
}
```

Generated schema:
```json
{
  "title": {
    "description": "The article title",
    "type": "string",
    "minLength": 10,
    "maxLength": 200
  },
  "content": {
    "description": "Article content",
    "type": "string"
  }
}
```

### Numeric Properties

```php
class Rating
{
    #[SchemaProperty(
        description: 'Rating from 1 to 5 stars',
        min: 1,
        max: 5
    )]
    public int $stars;

    #[SchemaProperty(description: 'Price in dollars')]
    public float $price;
}
```

Generated schema:
```json
{
  "stars": {
    "description": "Rating from 1 to 5 stars",
    "type": "integer",
    "minimum": 1,
    "maximum": 5
  },
  "price": {
    "description": "Price in dollars",
    "type": "number"
  }
}
```

### Boolean Properties

```php
class Settings
{
    #[SchemaProperty(description: 'Whether notifications are enabled')]
    public bool $notificationsEnabled;
}
```

## Nested Objects

Define nested classes to create complex structures:

```php
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Address
{
    #[SchemaProperty(description: 'The street name')]
    #[NotBlank]
    public string $street;

    public string $city;

    #[SchemaProperty(description: 'Postal/ZIP code')]
    public string $zip;
}

class Person
{
    #[NotBlank]
    public string $firstName;

    public string $lastName;

    // Nested object - type hint defines the schema
    public Address $address;
}
```

Usage:
```php
$person = $agent->structured(
    new UserMessage('John Doe lives at 123 Main St, New York, 10001'),
    Person::class
);

echo $person->address->city; // "New York"
```

## Arrays

### Array of Strings (Simple)

```php
class TagList
{
    #[SchemaProperty(description: 'List of tags', min: 1, max: 10)]
    public array $tags;
}
```

### Array of Objects

Use `anyOf` to specify the object type for arrays:

```php
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

class Tag
{
    #[SchemaProperty(description: 'The tag name')]
    public string $name;
}

class Article
{
    #[SchemaProperty(
        description: 'Article tags',
        anyOf: [Tag::class]
    )]
    #[ArrayOf(Tag::class)]
    public array $tags;
}
```

**Important**: Use both:
- `SchemaProperty(anyOf: [Class::class])` - For JSON schema generation
- `#[ArrayOf(Class::class)]` - For validation

### Nested Arrays (Deep Structures)

```php
class TagProperty
{
    #[SchemaProperty(description: 'The property value')]
    public string $value;
}

class Tag
{
    #[SchemaProperty(description: 'Tag name')]
    public string $name;

    #[SchemaProperty(
        description: 'Additional tag properties',
        anyOf: [TagProperty::class]
    )]
    #[ArrayOf(TagProperty::class)]
    public array $properties;
}

class Person
{
    public string $name;

    #[SchemaProperty(anyOf: [Tag::class])]
    #[ArrayOf(Tag::class)]
    public array $tags;
}
```

## Enums

### Backed Enums (String or Int)

```php
enum Status: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PENDING = 'pending';
}

class User
{
    public string $name;

    // Enum type hint automatically generates enum schema
    public Status $status;
}
```

Generated schema:
```json
{
  "status": {
    "type": "string",
    "enum": ["active", "inactive", "pending"]
  }
}
```

### Integer Enums

```php
enum Priority: int
{
    case LOW = 1;
    case MEDIUM = 2;
    case HIGH = 3;
}

class Task
{
    public string $title;
    public Priority $priority;
}
```

## Polymorphic Types (anyOf)

Use `anyOf` when a property can be one of several types:

```php
class FtpMode
{
    public string $mode;
    public string $account;
}

class EmailMode
{
    public string $mode;
    public string $mailingList;
}

class NotificationConfig
{
    #[SchemaProperty(anyOf: [FtpMode::class, EmailMode::class])]
    public array $modes;
}
```

### Discriminator Field

For polymorphic arrays, Neuron uses a `__classname__` discriminator field:

Generated schema includes:
```json
{
  "modes": {
    "type": "array",
    "items": {
      "anyOf": [
        {
          "type": "object",
          "properties": {
            "__classname__": {
              "type": "string",
              "enum": ["ftpmode"],
              "description": "This property is mandatory..."
            },
            "mode": {"type": "string"},
            "account": {"type": "string"}
          }
        },
        {
          "type": "object",
          "properties": {
            "__classname__": {
              "type": "string",
              "enum": ["emailmode"]
            },
            "mode": {"type": "string"},
            "mailingList": {"type": "string"}
          }
        }
      ]
    }
  }
}
```

The LLM must include `__classname__` in responses for proper deserialization.

## Validation Rules

Validation rules verify LLM output. If validation fails, Neuron can retry the request.

### String Validation

```php
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Rules\Length;
use NeuronAI\StructuredOutput\Validation\Rules\Email;
use NeuronAI\StructuredOutput\Validation\Rules\Url;
use NeuronAI\StructuredOutput\Validation\Rules\WordsCount;

class UserProfile
{
    #[NotBlank]  // Cannot be empty or whitespace only
    public string $username;

    #[Email]
    public string $email;

    #[Url]
    public string $website;

    #[Length(min: 10, max: 500)]
    public string $bio;

    #[WordsCount(min: 5, max: 100)]
    public string $summary;

    #[Length(exactly: 10)]  // Must be exactly 10 characters
    public string $code;
}
```

### Numeric Validation

```php
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThan;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThan;
use NeuronAI\StructuredOutput\Validation\Rules\OutOfRange;
use NeuronAI\StructuredOutput\Validation\Rules\EqualTo;

class Product
{
    #[GreaterThan(0)]
    public float $price;

    #[LowerThan(1000)]
    public int $stock;

    #[OutOfRange(min: 0, max: 100)]  // Must be within range
    public int $discountPercentage;

    #[EqualTo(42)]
    public int $answer;
}
```

### Array Validation

```php
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;
use NeuronAI\StructuredOutput\Validation\Rules\Count;

class Team
{
    #[ArrayOf(User::class)]
    public array $members;

    #[Count(min: 1, max: 10)]
    public array $tags;

    #[Count(exactly: 3)]
    public array $topThree;

    #[ArrayOf('string')]  // Array of scalar types
    public array $categories;
}
```

### Boolean & Null Validation

```php
use NeuronAI\StructuredOutput\Validation\Rules\IsTrue;
use NeuronAI\StructuredOutput\Validation\Rules\IsFalse;
use NeuronAI\StructuredOutput\Validation\Rules\IsNull;
use NeuronAI\StructuredOutput\Validation\Rules\IsNotNull;

class Settings
{
    #[IsTrue]
    public bool $agreedToTerms;

    #[IsFalse]
    public bool $isBlocked;

    #[IsNotNull]
    public ?string $optionalValue;
}
```

### Enum Validation

```php
use NeuronAI\StructuredOutput\Validation\Rules\Enum;

class Order
{
    // Validate against enum class
    #[Enum(class: Status::class)]
    public string $status;

    // Validate against explicit values
    #[Enum(values: ['urgent', 'normal', 'low'])]
    public string $priority;
}
```

### Comparison Validation

```php
use NeuronAI\StructuredOutput\Validation\Rules\EqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\NotEqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThanEqual;

class Comparison
{
    #[EqualTo(100)]
    public int $exactValue;

    #[NotEqualTo(0)]
    public int $nonZero;

    #[GreaterThanEqual(1)]
    public int $atLeastOne;

    #[LowerThanEqual(10)]
    public int $atMostTen;
}
```

### Format Validation

```php
use NeuronAI\StructuredOutput\Validation\Rules\Email;
use NeuronAI\StructuredOutput\Validation\Rules\Url;
use NeuronAI\StructuredOutput\Validation\Rules\IPAddress;
use NeuronAI\StructuredOutput\Validation\Rules\Json;

class Contact
{
    #[Email]
    public string $email;

    #[Url]
    public string $website;

    #[IPAddress]
    public string $serverIp;

    #[Json]  // Must be valid JSON string
    public string $metadata;
}
```

## Complete Validation Rules Reference

| Rule | Description | Example |
|------|-------------|---------|
| `NotBlank` | Not empty/whitespace | `#[NotBlank(allowNull: true)]` |
| `Length` | String length bounds | `#[Length(min: 1, max: 100)]` |
| `WordsCount` | Word count bounds | `#[WordsCount(min: 5, max: 50)]` |
| `Email` | Valid email format | `#[Email]` |
| `Url` | Valid URL format | `#[Url]` |
| `IPAddress` | Valid IP address | `#[IPAddress]` |
| `Json` | Valid JSON string | `#[Json]` |
| `Enum` | Value in allowed list | `#[Enum(class: Status::class)]` |
| `ArrayOf` | Array of specific type | `#[ArrayOf(User::class)]` |
| `Count` | Array item count | `#[Count(min: 1, max: 10)]` |
| `GreaterThan` | `value > reference` | `#[GreaterThan(0)]` |
| `GreaterThanOrEqual` | `value >= reference` | `#[GreaterThanEqual(0)]` |
| `LowerThan` | `value < reference` | `#[LowerThan(100)]` |
| `LowerThanOrEqual` | `value <= reference` | `#[LowerThanEqual(100)]` |
| `OutOfRange` | Value not in range | `#[OutOfRange(min: 0, max: 100)]` |
| `EqualTo` | Exact match | `#[EqualTo(42)]` |
| `NotEqualTo` | Not equal | `#[NotEqualTo(0)]` |
| `IsTrue` | Boolean true | `#[IsTrue]` |
| `IsFalse` | Boolean false | `#[IsFalse]` |
| `IsNull` | Must be null | `#[IsNull]` |
| `IsNotNull` | Must not be null | `#[IsNotNull]` |

## Usage with Agent

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;

// Define output class
class Person
{
    #[SchemaProperty(description: 'The person full name')]
    public string $name;

    #[SchemaProperty(description: 'What the person likes to eat')]
    public string $favoriteFood;

    #[SchemaProperty(description: 'Age in years', min: 0, max: 150)]
    public int $age;
}

// Use with agent
$agent = MyAgent::make();

$person = $agent->structured(
    new UserMessage("I'm John Doe, I'm 30 years old and I love pizza!"),
    Person::class
);

echo $person->name;          // "John Doe"
echo $person->age;           // 30
echo $person->favoriteFood;  // "pizza"
```

## Manual Validation

```php
use NeuronAI\StructuredOutput\Validation\Validator;

$person = new Person();
$person->name = '';
$person->age = -5;

$violations = Validator::validate($person);

if ($violations !== []) {
    foreach ($violations as $violation) {
        echo $violation . "\n";
    }
}
```

## Manual Deserialization

```php
use NeuronAI\StructuredOutput\Deserializer\Deserializer;

$json = '{"name": "John", "age": 30}';

$person = Deserializer::make()->fromJson($json, Person::class);
```

## Default Values

Properties with default values are optional:

```php
class Settings
{
    public string $theme = 'light';

    public int $timeout = 30;

    public bool $debug = false;
}
```

Generated schema includes defaults:
```json
{
  "theme": {"type": "string", "default": "light"},
  "timeout": {"type": "integer", "default": 30},
  "debug": {"type": "boolean", "default": false}
}
```

## DateTime Support

```php
class Event
{
    public string $name;

    public DateTime $startDate;

    public DateTimeImmutable $createdAt;
}
```

Deserializer handles various date formats:
- ISO 8601 strings: `"2024-01-15T10:30:00Z"`
- Unix timestamps: `1705320600`
- Relative formats: `"next Monday"`

## Best Practices

### 1. Always Add Descriptions

```php
// BAD - LLM doesn't know what to generate
public string $value;

// GOOD - Clear guidance for LLM
#[SchemaProperty(description: 'The monetary value in USD, must be positive')]
public float $value;
```

### 2. Use Validation for Critical Fields

```php
class UserRegistration
{
    #[NotBlank]
    #[Email]
    public string $email;

    #[Length(min: 8, max: 64)]
    public string $password;

    #[NotBlank]
    public string $username;
}
```

### 3. Keep Schemas Focused

```php
// BAD - Too many fields, harder for LLM
class UserProfile
{
    public string $name;
    public string $email;
    public string $phone;
    public string $address;
    public string $city;
    public string $country;
    public string $bio;
    public string $website;
    public string $company;
    public string $title;
    // ... 20 more fields
}

// GOOD - Focused on what you need
class UserContact
{
    public string $name;
    public string $email;
}
```

### 4. Use Enums for Fixed Options

```php
// BAD - LLM might return unexpected values
#[SchemaProperty(description: 'Priority: low, medium, or high')]
public string $priority;

// GOOD - Constrained options
enum Priority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}

public Priority $priority;
```

### 5. Combine SchemaProperty and Validation

```php
class Rating
{
    // Both guide LLM AND validate output
    #[SchemaProperty(description: 'Rating from 1 to 5', min: 1, max: 5)]
    #[GreaterThan(0)]
    #[LowerThan(6)]
    public int $stars;
}
```

## Common Patterns

### Contact Information

```php
class Contact
{
    #[NotBlank]
    public string $name;

    #[Email]
    public string $email;

    #[Length(min: 10, max: 15)]
    public string $phone;
}
```

### Address

```php
class Address
{
    #[NotBlank]
    public string $street;

    #[NotBlank]
    public string $city;

    #[NotBlank]
    #[Length(exactly: 5)]
    public string $zipCode;

    public string $country;
}
```

### Product

```php
class Product
{
    #[NotBlank]
    public string $name;

    #[SchemaProperty(description: 'Price in USD', min: 0)]
    public float $price;

    #[Count(min: 1)]
    public array $categories;

    public bool $inStock;
}
```

### Article with Tags

```php
class Tag
{
    #[NotBlank]
    public string $name;
}

class Article
{
    #[NotBlank]
    public string $title;

    #[WordsCount(min: 50, max: 500)]
    public string $summary;

    #[SchemaProperty(anyOf: [Tag::class])]
    #[ArrayOf(Tag::class)]
    public array $tags;
}
```
