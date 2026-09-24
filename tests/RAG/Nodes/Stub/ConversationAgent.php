<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Nodes\Stub;

use NeuronAI\Agent\Agent;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Nodes\ConversationIngestionNode;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Workflow\Node;

class ConversationAgent extends Agent
{
    public VectorStoreInterface $conversationStore;
    public EmbeddingsProviderInterface $conversationEmbeddings;

    /**
     * @return Node[]
     */
    protected function exitNodes(): array
    {
        return [new ConversationIngestionNode($this->conversationStore, $this->conversationEmbeddings)];
    }
}
