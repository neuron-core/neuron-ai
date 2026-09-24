<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\Workflow;

/**
 * A workflow that declares a business key as its workflow ID, so its runs
 * are findable by that key instead of by a generated one. Suspends in the
 * middle (InterruptableNode) so continuations can be exercised.
 */
class KeyedWorkflow extends Workflow
{
    protected ?string $key = null;

    public function withDeclaredWorkflowId(?string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function workflowId(): ?string
    {
        return $this->key;
    }

    protected function nodes(): array
    {
        return [
            new NodeOne(),
            new InterruptableNode(),
            new NodeThree(),
        ];
    }
}
