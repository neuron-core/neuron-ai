<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use LogicException;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Persistence\Serializer;

/**
 * Replaying a cached step decodes its record, so a replay that keeps landing
 * on the same step fails here instead of hanging the test.
 */
class ReplayBudgetSerializer implements Serializer
{
    protected PhpSerializer $serializer;

    protected int $decoded = 0;

    public function __construct(protected int $budget = 200)
    {
        $this->serializer = new PhpSerializer();
    }

    public function serialize(mixed $value): string
    {
        return $this->serializer->serialize($value);
    }

    public function unserialize(string $data): mixed
    {
        if (++$this->decoded > $this->budget) {
            throw new LogicException("Replay decoded more than {$this->budget} records without finishing.");
        }

        return $this->serializer->unserialize($data);
    }
}
