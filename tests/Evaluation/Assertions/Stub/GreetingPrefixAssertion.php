<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Assertions\Stub;

use NeuronAI\Evaluation\Assertions\StringStartsWith;

/**
 * An application assertion built by extending a framework one
 */
class GreetingPrefixAssertion extends StringStartsWith
{
    public function __construct()
    {
        parent::__construct('Hello');
    }
}
