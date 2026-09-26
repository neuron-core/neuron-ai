<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

class NestedObjectCastingTest extends TestCase
{
    public function test_nested_object_fields_are_bound_as_their_declared_types(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'transfer';

            protected function properties(): array
            {
                return [new ObjectProperty('transfer', required: true, properties: [
                    new ToolProperty('amount', PropertyType::INTEGER, required: true),
                    new ToolProperty('international', PropertyType::BOOLEAN, required: true),
                ])];
            }

            protected function approvalPolicy(): bool|string
            {
                return $this->inputs['transfer']['international'] === true ? 'International transfer' : false;
            }

            public function __invoke(array $transfer): string
            {
                return 'sent';
            }
        };

        $tool->setInputs(['transfer' => ['amount' => '500', 'international' => 'true']]);

        $this->assertSame(['transfer' => ['amount' => 500, 'international' => true]], $tool->getInputs());
        $this->assertSame('International transfer', $tool->requiresApproval());
    }

    public function test_an_invalid_nested_field_is_rejected_with_its_path(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'transfer';

            protected function properties(): array
            {
                return [new ObjectProperty('transfer', required: true, properties: [new ToolProperty('amount', PropertyType::INTEGER)])];
            }

            public function __invoke(array $transfer): string
            {
                return 'sent';
            }
        };

        $tool->setInputs(['transfer' => ['amount' => 'lots']])->execute();

        $this->assertSame('Parameter "transfer" field "amount" must be of type integer, string given.', (string) $tool->getResult());
    }

    public function test_a_scalar_for_an_object_is_rejected(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'transfer';

            protected function properties(): array
            {
                return [new ObjectProperty('transfer', required: true, properties: [new ToolProperty('amount', PropertyType::INTEGER)])];
            }

            public function __invoke(mixed $transfer): string
            {
                return 'sent';
            }
        };

        $tool->setInputs(['transfer' => 'all my money'])->execute();

        $this->assertSame('Parameter "transfer" must be of type object, string given.', (string) $tool->getResult());
    }
}
