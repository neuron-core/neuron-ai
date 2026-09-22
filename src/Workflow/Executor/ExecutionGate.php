<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Workflow;
use WeakMap;

/** Local overlap protection, independent of persisted ownership. @internal */
final class ExecutionGate
{
    /** @var WeakMap<Workflow, bool>|null */
    protected static ?WeakMap $active = null;

    public static function assertAvailable(Workflow $definition): void
    {
        if (isset(self::$active[$definition])) {
            throw new WorkflowException('An execution segment is already in flight on this workflow instance.');
        }
    }

    public static function acquire(Workflow $definition): void
    {
        self::assertAvailable($definition);
        self::$active ??= new WeakMap();
        self::$active[$definition] = true;
    }

    public static function release(Workflow $definition): void
    {
        unset(self::$active[$definition]);
    }
}
