<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;

/**
 * Lets another worker claim the run right before the next conditional delete,
 * as if its claim landed between the last step commit and the cleanup.
 */
class ClaimBeforeCleanupPersistence extends InMemoryPersistence
{
    public bool $claimBeforeCleanup = false;

    public function deleteIfUnchanged(string $partition, string $conditionKey, string $expectedValue): bool
    {
        if ($this->claimBeforeCleanup) {
            $this->claimBeforeCleanup = false;
            $serializer = new PhpSerializer();
            $control = $serializer->unserialize($expectedValue);
            if ($control instanceof WorkflowControl) {
                $this->writeIfUnchanged($partition, $conditionKey, $expectedValue, [
                    $conditionKey => $serializer->serialize($control->claim(null)),
                ]);
            }
        }

        return parent::deleteIfUnchanged($partition, $conditionKey, $expectedValue);
    }
}
