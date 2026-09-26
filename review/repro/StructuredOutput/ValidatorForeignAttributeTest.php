<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use Attribute;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Validator;
use PHPUnit\Framework\TestCase;

#[Attribute(Attribute::TARGET_CLASS)]
class ClassOnlyMarker
{
}

class ValidatorForeignAttributeTest extends TestCase
{
    public function test_attributes_from_uninstalled_packages_are_ignored(): void
    {
        $object = new class () {
            #[\Vendor\Package\NotInstalledAttribute]
            #[NotBlank]
            public string $name = '';
        };

        $this->assertSame(['name cannot be blank'], Validator::validate($object));
    }

    public function test_attributes_not_targeting_properties_are_ignored(): void
    {
        $object = new class () {
            #[ClassOnlyMarker]
            #[NotBlank]
            public string $name = '';
        };

        $this->assertSame(['name cannot be blank'], Validator::validate($object));
    }
}
