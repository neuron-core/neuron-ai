<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Deserializer;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\Deserializer\DeserializerException;
use NeuronAI\StructuredOutput\JsonSchema;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\Testing\FakeAIProvider;
use PHPUnit\Framework\TestCase;

class RequiredProfile
{
    public string $name;

    public int $age;
}

class DeserializerRequiredPropertyTest extends TestCase
{
    public function test_schema_marks_non_nullable_property_without_default_as_required(): void
    {
        $schema = (new JsonSchema())->generate(RequiredProfile::class);

        $this->assertSame(['name', 'age'], $schema['required']);
    }

    public function test_missing_required_property_is_rejected(): void
    {
        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('age');

        Deserializer::make()->fromJson('{"name":"Ada"}', RequiredProfile::class);
    }

    public function test_explicit_null_for_non_nullable_property_is_rejected(): void
    {
        $this->expectException(DeserializerException::class);
        $this->expectExceptionMessage('age');

        Deserializer::make()->fromJson('{"name":"Ada","age":null}', RequiredProfile::class);
    }

    public function test_explicit_null_overrides_non_null_default_of_nullable_property(): void
    {
        $class = new class () {
            public ?string $nickname = 'guest';
        };

        $obj = Deserializer::make()->fromJson('{"nickname":null}', $class::class);

        $this->assertNull($obj->nickname);
    }

    public function test_optional_property_declared_by_attribute_may_be_missing(): void
    {
        $class = new class () {
            public string $name;

            #[SchemaProperty(required: false)]
            public int $age;
        };

        $obj = Deserializer::make()->fromJson('{"name":"Ada"}', $class::class);

        $this->assertSame('Ada', $obj->name);
        $this->assertFalse(isset($obj->age));
    }

    public function test_agent_retries_when_model_omits_a_required_property(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name":"Ada"}'),
            new AssistantMessage('{"name":"Ada","age":36}'),
        );

        $profile = Agent::make()
            ->setAiProvider($provider)
            ->structured(new UserMessage('Profile of Ada'), RequiredProfile::class, 1);

        $provider->assertCallCount(2);
        $this->assertSame(36, $profile->age);
    }
}
