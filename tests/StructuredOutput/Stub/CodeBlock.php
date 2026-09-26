<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput\Stub;

/**
 * Declares a property named like the default discriminator to detect it leaking into the target object.
 */
class CodeBlock
{
    public string $code;

    public ?string $__classname__ = null;
}
