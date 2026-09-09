<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use Closure;
use NeuronAI\Workflow\WorkflowState;

class RestorableState extends WorkflowState
{
    public ?Closure $operation = null;

    public function __serialize(): array
    {
        $properties = get_object_vars($this);
        unset($properties['operation']);

        return $properties;
    }

    public function __unserialize(array $properties): void
    {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }
}
