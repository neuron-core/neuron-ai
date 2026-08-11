<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Workflow;

/**
 * A workflow that declares a business key, so its runs are addressable by
 * that key instead of by the engine-generated runId. Suspends in the middle
 * (InterruptableNode) so continuations can be exercised.
 */
class CorrelatedWorkflow extends Workflow
{
    protected ?string $key = null;

    public function withCorrelationKey(?string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function correlationKey(): ?string
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
