<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Validation;

use NeuronAI\StructuredOutput\StructuredOutputException;
use NeuronAI\StructuredOutput\Validation\Rules\Count;
use PHPUnit\Framework\TestCase;

class CountMisconfigurationMessageTest extends TestCase
{
    public function test_misconfiguration_message_names_the_count_rule(): void
    {
        $this->expectException(StructuredOutputException::class);
        $this->expectExceptionMessage('Either option "min" or "max" must be given for validation rule "Count"');

        $violations = [];
        (new Count())->validate('tags', [], $violations);
    }
}
