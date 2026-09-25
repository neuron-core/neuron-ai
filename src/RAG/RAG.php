<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\Agent\Agent;
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
use function get_debug_type;

/**
 * @method static static make(?string $workflowId = null, ?WorkflowState $state = null)
 */
class RAG extends Agent
{
    use ResolveVectorStore;
    use ResolveEmbeddingProvider;
    use ResolveRetrieval;

    /**
     * @var PreProcessorInterface[]|null
     */
    protected ?array $preProcessors = null;

    /**
     * @var PostProcessorInterface[]|null
     */
    protected ?array $postProcessors = null;

    /**
     * The retrieval chain replaces the Agent's StartNode as the entry chain:
     * RAG's inference event is born at its end, in InstructionsNode.
     *
     * @return Node[]
     */
    protected function entryNodes(): array
    {
        return [
            new PreProcessNode($this->preProcessors ?? $this->preProcessors()),
            new RetrievalNode($this->resolveRetrieval(), $this->resolveRetrievalScope()),
            new PostProcessNode($this->postProcessors ?? $this->postProcessors()),
            new InstructionsNode(),
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

            $grouped[$sourceType][$sourceName] ??= [];

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
    public function setPreProcessors(array $preProcessors): static
    {
        foreach ($preProcessors as $processor) {
            if (! $processor instanceof PreProcessorInterface) {
                throw new AgentException(get_debug_type($processor)." must implement ".PreProcessorInterface::class);
            }
        }

        $this->preProcessors = $preProcessors;

        return $this;
    }

    /**
     * @param PostProcessorInterface[] $postProcessors
     * @throws AgentException
     */
    public function setPostProcessors(array $postProcessors): static
    {
        foreach ($postProcessors as $processor) {
            if (! $processor instanceof PostProcessorInterface) {
                throw new AgentException(get_debug_type($processor)." must implement ".PostProcessorInterface::class);
            }
        }

        $this->postProcessors = $postProcessors;

        return $this;
    }

    /**
     * @return PreProcessorInterface[]
     */
    protected function preProcessors(): array
    {
        return [];
    }

    /**
     * @return PostProcessorInterface[]
     */
    protected function postProcessors(): array
    {
        return [];
    }
}
