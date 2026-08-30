<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\RAG\Nodes\InstructionsNode;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\RAG\Nodes\RetrievalNode;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\Workflow\Node;

use function array_chunk;

/**
 * @method static static make(?string $workflowId = null, ?WorkflowState $state = null, ?string $threadId = null)
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
        // Bootstrap first: it rewrites the instructions (toolkit guidelines),
        // so resolving them before it would hand the node the stale message.
        $tools = $this->bootstrapTools();

        return [
            new PreProcessNode($this->getChatHistory(), $this->preProcessors()),
            new RetrievalNode($this->resolveRetrieval(), $this->resolveRetrievalScope()),
            new PostProcessNode($this->postProcessors()),
            new InstructionsNode(
                $this->getInstructions(),
                $tools,
                $this->getMemory() instanceof MemoryInterface,
            ),
        ];
    }

    /**
     * @param Document[] $documents
     */
    public function addDocuments(array $documents, int $chunkSize = 50): void
    {
        if ($chunkSize < 1) {
            throw new AgentException('RAG document chunk size must be greater than zero.');
        }

        $vectorStore = $this->resolveVectorStore();

        foreach (array_chunk($documents, $chunkSize) as $chunk) {
            foreach ($chunk as $document) {
                $vectorStore->getSchema()->validate($document);
            }

            $vectorStore->addDocuments(
                $this->resolveEmbeddingsProvider()->embedDocuments($chunk)
            );
        }
    }

    /**
     * Destructive per source: existing documents of each source are deleted first.
     *
     * @param Document[] $documents
     * @throws AgentException|VectorStoreException
     */
    public function reindexBySource(array $documents, int $chunkSize = 50): void
    {
        $grouped = [];

        foreach ($documents as $document) {
            $sourceType = $document->getSourceType();
            $sourceName = $document->getSourceName();

            if (!isset($grouped[$sourceType][$sourceName])) {
                $grouped[$sourceType][$sourceName] = [];
            }

            $grouped[$sourceType][$sourceName][] = $document;
        }

        foreach ($grouped as $sourceType => $sources) {
            foreach ($sources as $sourceName => $sourceDocuments) {
                $this->resolveVectorStore()->delete(FilterGroup::and(
                    Filter::eq('sourceType', $sourceType),
                    Filter::eq('sourceName', $sourceName),
                ));
                $this->addDocuments($sourceDocuments, $chunkSize);
            }
        }
    }

    /**
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
     * @return PreProcessorInterface[]
     */
    protected function preProcessors(): array
    {
        return $this->preProcessors;
    }

    /**
     * @return PostProcessorInterface[]
     */
    protected function postProcessors(): array
    {
        return $this->postProcessors;
    }
}
