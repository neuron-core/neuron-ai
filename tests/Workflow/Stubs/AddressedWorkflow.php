<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stubs;

use NeuronAI\Workflow\Workflow;

/**
 * A workflow that declares a business key as its address, so its runs are
 * addressable by that key instead of by a generated one. Suspends in the
 * middle (InterruptableNode) so continuations can be exercised.
 */
class AddressedWorkflow extends Workflow
{
    protected ?string $key = null;

    public function withDeclaredAddress(?string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function address(): ?string
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
