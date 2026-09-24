<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stub;

use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\WorkflowStatus;
use RuntimeException;

class CrashAfterClaim extends InMemoryPersistence
{
    public bool $crash = false;

    public function writeIfUnchanged(string $partition, string $conditionKey, string $expectedValue, array $records): bool
    {
        $written = parent::writeIfUnchanged($partition, $conditionKey, $expectedValue, $records);
        $control = isset($records['__control']) ? (new PhpSerializer())->unserialize($records['__control']) : null;
        if ($written && $this->crash && $control instanceof WorkflowControl
            && $control->status === WorkflowStatus::Running) {
            $this->crash = false;
            throw new RuntimeException('Lost claim acknowledgement');
        }
        return $written;
    }
}
