<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Stub;

use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\RAG\RAG;
use NeuronAI\Workflow\Node;

use function array_map;

class ItalianRAG extends RAG
{
    /** @return Node[] */
    protected function entryNodes(): array
    {
        return array_map(
            fn (Node $node): Node => $node instanceof PreProcessNode
                ? new ItalianPreProcessNode($this->preProcessors ?? $this->preProcessors())
                : $node,
            parent::entryNodes(),
        );
    }
}
