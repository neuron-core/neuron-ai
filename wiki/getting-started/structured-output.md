---
description: Enforce the Agent output based on the provided schema.
---

# Structured Output

{% hint style="info" %}
PREREQUISITES

This guide assumes you are already familiar with the following concepts:

* [Agent](agent.md)
* [Tool & Function Call](tools.md)
{% endhint %}

There are many use cases where we need Agents to understand natural language, but output in a _structured format_. One common use-case is extracting data from text to insert into a database or use with some other downstream system. This guide covers how Neuron allows you to enforce structured outputs from the agent.

<figure><img src="../.gitbook/assets/Neuron AI.png" alt=""><figcaption></figcaption></figure>

### How to use Structured Output

The central concept is that the output structure of LLM responses needs to be represented in some way. The schema that Neuron validates against is defined by PHP type hints. Basically you have to define a class with strictly typed properties:

```php
<?php

namespace App\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;

class Person 
{
    #[SchemaProperty(description: 'The user name.', required: true)]
    public string $name;
    
    #[SchemaProperty(description: 'What the user love to eat.', required: false)]
    public string $preference;
}
```

Neuron generates the corresponding JSON schema from the PHP object to instruct the underlying model about your required data format. Then the agent parse the LLM output to extract data and returns an object instance filled with appropriate values:

```php
use NeuronAI\Chat\Messages\UserMessage;

// Talk to the agent requiring the structured output
$person = MyAgent::make()->structured(
    new UserMessage("I'm John and I like pizza!"),
    Person::class
);

echo $person->name.' like '.$person->preference;
// John like pizza
```

### Default output class

You can also encapsulate the output format into the Agent implementation, so it will be the Agent standard output format. You always need to call the `structured()` method to require strict output.

```php
use NeuronAI\Chat\Messages\UserMessage;

// Encapsulate the default output format 
class MyAgent extends Agent
{
    ...

    protected function getOutputClass(): string
    {
        return Person::class;
    }
}

// Always use the structured method if you want to get structured output
$person = MyAgent::make()
    ->structured(new UserMessage("I'm John and I like pizza"));

echo $person->name.' like '.$person->preference;
// John like pizza
```

### Control the output generation

Neuron requires you to define two layers of rules to create the structured output class.&#x20;

The first is the `SchemaProperty` attribute that allows you to control the JSON schema sent to the LLM to understand the required data format.

The second layer is validation. Validation attributes will ensure data gathered from the LLM response are consistent with your requirements.

<figure><img src="../.gitbook/assets/Structured Output Control.png" alt=""><figcaption></figcaption></figure>

### SchemaProperty

We strongly recommend to use the `SchemaProperty` attribute to define at least the description, to allow the LLM understand the purpose of a property, and the required flag:

```php
<?php

namespace App\Neuron\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;

class Person 
{
    #[SchemaProperty(description: 'The user name.', required: true)]
    public string $name;
    
    #[SchemaProperty(description: 'What the user love to eat.', required: false)]
    public string $preference;
}
```

### Validation

The Validation component already contains many validation rules that you can apply to the output class properties. The example below shows you how to mark the name property as required (_NotBlank_):

```php
<?php

namespace App\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Person 
{
    #[SchemaProperty(description: 'The user name.')]
    #[NotBlank]
    public string $name;
    
    #[SchemaProperty(description: 'What the user love to eat.')]
    public string $preference;
}
```

### Nested Class

You can construct complex output structures using other PHP objects as a property type. Following the example of a the `Person` class we can add the `address` property typed as another structured class.

```php
<?php

namespace App\Dto;

use NeuronAI\StructuredOutput\Property;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Valid;

class Person 
{
    #[SchemaProperty(description: 'The user name.', required: true)]
    #[NotBlank]
    public string $name;
    
    #[SchemaProperty(description: 'What user love to eat.', required: true)]
    public string $preference;
    
    #[SchemaProperty(description: 'The address to complete the delivery.', required: true)]
    public Address $address;
}
```

In the `Address` definition we require only the street and zip code properties, and allow city to be empty.&#x20;

```php
<?php

namespace App\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Address
{
    #[SchemaProperty(description: 'The name of the street.', required: true)]
    #[NotBlank]
    public string $street;

    #[SchemaProperty(description: 'The name of the city.', required: false)]
    public string $city;

    #[SchemaProperty(description: 'The zip code of the address.', required: true)]
    #[NotBlank]
    public string $zip;
}
```

Now when you ask the agent for the structured output you will get the filled instance back:

<pre class="language-php"><code class="lang-php">use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\AgentMonitoring;

<strong>// Talk to the agent requiring the structured output
</strong>$person = MyAgent::make()->structured(
    new UserMessage("I'm John and I want a pizza at st. James Street 00560!"),
    Person::class
);

echo $person->name.' like '.$person->preference.'. Address: '.$person->address->street;
// John like pizza. Address: st.James Street
</code></pre>

### Array

If you declare a property as an array Neuron assumes the list of items to be a list of string. Assume we want to add a list of tags to the Person object:

```php
<?php

namespace App\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Person 
{
    #[SchemaProperty(description: 'The user name.', required: true)]
    #[NotBlank]
    public string $name;
    
    #[SchemaPropertyerty(description: 'What user love to eat.', required: true)]
    public string $preference;
    
    #[SchemaProperty(description: 'The list of tag for the user profile.', required: true)]
    public array $tags;
}
```

Without any additional information the agent will assume that the `tags` property is an array of strings by default.&#x20;

```php
echo $person->tags;

/*
[
    'tag 1',
    'tag 2',
    ...
]
*/
```

### Array of objects

It could be needed to populate the list of tags with another structured data type. To do this you must add the `ArrayOf` attribute for properly validation, and specify the fully qualified class namespace in the doc-block for properly deserialization:

```php
<?php

namespace App\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

class Person 
{
    #[SchemaProperty(description: 'The user name.', required: true)]
    #[NotBlank]
    public string $name;
    
    #[SchemaProperty(description: 'What user love to eat.', required: true)]
    public string $preference;
    
    /**
     * @var \App\Agent\Models\Tag[]
     */
    #[SchemaProperty(description: 'The list of tag for the user profile.', required: true)]
    #[ArrayOf(Tag::class)]
    public array $tags;
}
```

And here is the hypotetical implementation of the `Tag` class with its own validation rules and property info:

```php
<?php

namespace App\Dto;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Tag
{
    #[SchemaProperty(description: 'The name of the tag', required: true)]
    #[NotBlank]
    public string $name;
}
```

### Max Retries

Since the LLM are not perfectly deterministic it's mandatory to have a retry mechanism in place if something is missing in the LLM response.

By default Neuron extracts and validates the data from the LLM response and if there is one or more validation errors automatically retry the request just one more time informing the LLM about what went wrong and for what properties.&#x20;

You can eventually customize the number of times the agent must retry to get a correct answer from the LLM:

```php
$person = MyAgent::make()->structured(
    messages: new UserMessage("I'm John and I like pizza!"),
    class: Person::class,
    maxRetries: 3
);
```

If you work with a less capable LLM consider to use a number of retries balancing the probability to get e valid answer, and the potential token consumption.

You can disable retry just passing zero. It will be a one shot attempt:

```php
$person = MyAgent::make()->structured(
    messages: new UserMessage("I'm John and I like pizza!"),
    class: Person::class,
    maxRetries: 0
);
```

## Monitoring

To watch inside this workflow you should connect your Agent to the [Inspector monitoring dashboard](https://inspector.dev/) in order to see the tool call execution flow in real-time.

After you sign up at the link above, make sure to set the `INSPECTOR_INGESTION_KEY` variable in the application environment file to start monitoring:

{% code title=".env" %}
```
INSPECTOR_INGESTION_KEY=nwse877auxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```
{% endcode %}

<figure><img src="../.gitbook/assets/Neuron Structured Observability.png" alt=""><figcaption></figcaption></figure>

Each segment bring its own debug information to follow the agent execution in real time:

<figure><img src="../.gitbook/assets/neuron structured segment.png" alt=""><figcaption></figcaption></figure>

{% hint style="info" %}
Learn how to enable [**observability**](../advanced/observability.md) in the next section.
{% endhint %}

## Available Validation Rules

### #\[NotBlank]

The property under validation cannot be blank. It accept the allowNull flag to treat explicitly null value as empty equivalent or not.

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;

class Person 
{
    #[NotBlank(allowNull: false)]
    public string $name;
}
```

### #\[Length]

This rule works only on `string` properties. The property under validation must respect the length constraints:

<pre class="language-php"><code class="lang-php"><strong>namespace App\Dto;
</strong>
use NeuronAI\StructuredOutput\Validation\Rules\Length;

class Person 
{
    #[Length(min: 1, max: 10)]
    public string $name;
    
    #[Length(exactly: 5)]
    public string $zip_code;
}
</code></pre>

### #\[Count]

This rule works only on array properties. The property under validation must have a size matching the constraint definition:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\Count;

class Person 
{
    #[Count(min: 1, max: 3)]
    public array $dogs;
    
    #[Count(exactly: 1)]
    public array $children;
}
```

### #\[EqualTo] - #\[NotEqualTo]

These rules have the same structure and meaning, and accept a single argument to define the value to compare against. The property under validation must be strictly equal (_#\[EqualTo]_) or different (_#\[NotEqualTo]_) than the reference value:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\EqualTo;
use NeuronAI\StructuredOutput\Validation\Rules\NotEqualTo;

class Person 
{
    #[EqualTo(reference: 'Rome')]
    public string $city;
    
    #[NotEqualTo(reference: '00502')]
    public string $zip_code;
}
```

### #\[GreaterThan] - #\[GreaterThanEqual]

These rules have the same structure and meaning, and accept a single argument to define the value to compare against. The property under validation must be strictly greater (_#\[GreaterThan]_) or equal (_#\[GreaterThanEqual]_) than the reference value:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\GreaterThan;
use NeuronAI\StructuredOutput\Validation\Rules\GreaterThanEqual;

class Person 
{
    #[GreaterThan(reference: 17)]
    public int $age;
    
    #[GreaterThanEqual(reference: 1)]
    public int $cars;
}
```

### #\[LowerThan] - #\[LowerThanEqual]

These rules have the same structure and meaning, and accept a single argument to define the value to compare against. The property under validation must be strictly lower (_#\[LowerThan]_) or equal (_#\[LowerThanEqual]_) than the reference value:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\LowerThan;
use NeuronAI\StructuredOutput\Validation\Rules\LowerThanEqual;

class Person 
{
    #[LowerThan(reference: 50)]
    public int $age;
    
    #[LowerThanEqual(reference: 1)]
    public int $cars;
}
```

### #\[IsFalse] - #\[IsTrue]

The property under validation must have exactly the boolean value defined by the rule:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\IsFalse;
use NeuronAI\StructuredOutput\Validation\Rules\IsTrue;

class Phone
{
    #[IsFalse]
    public bool $iphone;
    
    #[IsTrue]
    public bool $refurbed;
}
```

### #\[IsNull] - #\[IsNotNull]

The property under validation must respect the nullable condition defined by the rule:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\IsNotNull;
use NeuronAI\StructuredOutput\Validation\Rules\IsNull;

class Phone
{
    #[IsNotNull]
    public string $brand;
    
    #[IsNull]
    public ?string $test;
}
```

### #\[Json]

The property under validation must contains a valid JSON string:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\Json;

class Person
{
    #[Json]
    public string $address;
}
```

### #\[Url]

The property under validation must contains a valid URL:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\Url;

class Person
{
    #[Url]
    public string $website;
}
```

### #\[Email]

The property under validation must contains a valid Email address:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\Email;

class Person
{
    #[Email]
    public string $email;
}
```

### #\[IpAddress]

The property under validation must contains a valid IP address:

```php
namespace App\Dto;

use NeuronAI\StructuredOutput\Validation\Rules\IpAddress;

class Person
{
    #[IpAddress]
    public string $ip;
}
```

### #\[ArrayOf]

The property under validation must be an array that contains all of the given type of object. Notice that you also need to add the doc-block in order to make the agent able to instance the correct class. Use the full class namespace in the doc-block.

<pre class="language-php"><code class="lang-php"><strong>namespace App\Dto;
</strong>
use NeuronAI\StructuredOutput\Validation\Rules\ArrayOf;

class Person
{
    /**
     * @var \App\Dto\Tag[]
     */
    #[ArrayOf(Tag::class)]
    public array $tags;
}
</code></pre>
