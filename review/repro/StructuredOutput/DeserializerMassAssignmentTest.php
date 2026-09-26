<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Deserializer;

use NeuronAI\StructuredOutput\Deserializer\Deserializer;
use NeuronAI\StructuredOutput\JsonSchema;
use PHPUnit\Framework\TestCase;

use function array_keys;

class DeserializerMassAssignmentTest extends TestCase
{
    protected function tearDown(): void
    {
        MassAssignmentTarget::$registryCounter = 0;
    }

    public function test_non_public_properties_are_not_writable_from_model_output(): void
    {
        $target = Deserializer::make()->fromJson(
            '{"name": "John", "isAdmin": true, "internalToken": "forged"}',
            MassAssignmentTarget::class
        );

        $this->assertInstanceOf(MassAssignmentTarget::class, $target);
        $this->assertSame('John', $target->name);
        $this->assertFalse($target->isAdmin());
        $this->assertSame('server-side', $target->internalToken());
    }

    public function test_static_properties_are_not_writable_from_model_output(): void
    {
        Deserializer::make()->fromJson('{"name": "John", "registryCounter": 999}', MassAssignmentTarget::class);

        $this->assertSame(0, MassAssignmentTarget::$registryCounter);
    }

    public function test_static_properties_are_not_exposed_in_the_schema(): void
    {
        $schema = JsonSchema::make()->generate(MassAssignmentTarget::class);

        $this->assertSame(['name'], array_keys($schema['properties']));
    }
}

class MassAssignmentTarget
{
    public static int $registryCounter = 0;

    public string $name;

    protected bool $isAdmin = false;

    private string $internalToken = 'server-side';

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function internalToken(): string
    {
        return $this->internalToken;
    }
}
