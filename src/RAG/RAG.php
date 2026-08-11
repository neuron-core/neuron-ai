<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\Agent\Agent;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\RAG\Nodes\InstructionsNode;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\RAG\Nodes\RetrievalNode;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\Workflow\Node;

use function array_chunk;
use function array_keys;
use function explode;

/**
 * @method static static make(?string $runId = null, ?WorkflowState $state = null)
 */
class RAG extends Agent
{
    use ResolveVectorStore;
    use ResolveEmbeddingProvider;
    use ResolveRetrieval;

    /**
     * @var PreProcessorInterface[]
     */
    protected array $preProcessors = [];

    /**
     * @var PostProcessorInterface[]
     */
    protected array $postProcessors = [];

    /**
     * The retrieval chain replaces the Agent's StartNode as the entry chain:
     * RAG's inference event is born at its end, in InstructionsNode.
     *
     * @return Node[]
     */
    protected function entryNodes(): array
    {
        return [
            new PreProcessNode($this->getChatHistory(), $this->preProcessors()),
            new RetrievalNode($this->resolveRetrieval()),
            new PostProcessNode($this->postProcessors()),
            new InstructionsNode($this->resolveInstructions(), $this->bootstrapTools()),
        ];
    }

    /**
     * Feed the vector store with documents.
     *
     * @param Document[] $documents
     */
    public function addDocuments(array $documents, int $chunkSize = 50): void
    {
        foreach (array_chunk($documents, $chunkSize) as $chunk) {
            $this->resolveVectorStore()->addDocuments(
                $this->resolveEmbeddingsProvider()->embedDocuments($chunk)
            );
        }
    }

    /**
     * Reindex documents by source (delete old, add new).
     *
     * @param Document[] $documents
     */
    public function reindexBySource(array $documents, int $chunkSize = 50): void
    {
        $grouped = [];

        foreach ($documents as $document) {
            $key = $document->sourceType . ':' . $document->sourceName;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $document;
        }

        foreach (array_keys($grouped) as $key) {
            [$sourceType, $sourceName] = explode(':', $key);
            $this->resolveVectorStore()->deleteBy($sourceType, $sourceName);
            $this->addDocuments($grouped[$key], $chunkSize);
        }
    }

    /**
     * Set preprocessors for query transformation.
     *
     * @param PreProcessorInterface[] $preProcessors
     * @throws AgentException
     */
    public function setPreProcessors(array $preProcessors): RAG
    {
        foreach ($preProcessors as $processor) {
            if (! $processor instanceof PreProcessorInterface) {
                throw new AgentException($processor::class." must implement ".PreProcessorInterface::class);
            }

            $this->preProcessors[] = $processor;
        }

        return $this;
    }

    /**
     * Set post-processors for document transformation.
     *
     * @param PostProcessorInterface[] $postProcessors
     * @throws AgentException
     */
    public function setPostProcessors(array $postProcessors): RAG
    {
        foreach ($postProcessors as $processor) {
            if (! $processor instanceof PostProcessorInterface) {
                throw new AgentException($processor::class." must implement ".PostProcessorInterface::class);
            }

            $this->postProcessors[] = $processor;
        }

        return $this;
    }

    /**
     * Get configured preprocessors.
     *
     * @return PreProcessorInterface[]
     */
    protected function preProcessors(): array
    {
        return $this->preProcessors;
    }

    /**
     * Get configured post-processors.
     *
     * @return PostProcessorInterface[]
     */
    protected function postProcessors(): array
    {
        return $this->postProcessors;
    }
}
