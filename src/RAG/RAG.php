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
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\Workflow\Node;

use function array_chunk;
use function array_map;
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
     * @throws AgentException
     * @throws DocumentSchemaException
     */
    public function addDocuments(array $documents, int $chunkSize = 50): void
    {
        $this->validateBatch($documents, $chunkSize);

        foreach (array_chunk($documents, $chunkSize) as $chunk) {
            $this->resolveVectorStore()->addDocuments(
                $this->resolveEmbeddingsProvider()->embedDocuments($chunk)
            );
        }
    }

    /**
     * Replaces the stored documents of each source. The whole batch is validated, and each source's
     * chunks are embedded before its old documents are deleted, so a failed validation or embedding
     * call leaves the source as it was.
     *
     * @param Document[] $documents
     * @throws AgentException|DocumentSchemaException|VectorStoreException
     */
    public function reindexBySource(array $documents, int $chunkSize = 50): void
    {
        $this->validateBatch($documents, $chunkSize);

        $grouped = [];

        foreach ($documents as $document) {
            $sourceType = $document->getSourceType();
            $sourceName = $document->getSourceName();

            $grouped[$sourceType][$sourceName] ??= [];

            $grouped[$sourceType][$sourceName][] = $document;
        }

        foreach ($grouped as $sources) {
            foreach ($sources as $sourceDocuments) {
                $chunks = array_map(
                    fn (array $chunk): array => $this->resolveEmbeddingsProvider()->embedDocuments($chunk),
                    array_chunk($sourceDocuments, $chunkSize)
                );

                // Read from a document: as an array key, a name made of digits has become an integer
                $this->resolveVectorStore()->delete(FilterGroup::and(
                    Filter::eq('sourceType', $sourceDocuments[0]->getSourceType()),
                    Filter::eq('sourceName', $sourceDocuments[0]->getSourceName()),
                ));

                foreach ($chunks as $chunk) {
                    $this->resolveVectorStore()->addDocuments($chunk);
                }
            }
        }
    }

    /**
     * The whole batch is checked before anything is embedded or stored.
     *
     * @param Document[] $documents
     * @throws AgentException|DocumentSchemaException
     */
    protected function validateBatch(array $documents, int $chunkSize): void
    {
        if ($chunkSize < 1) {
            throw new AgentException('RAG document chunk size must be greater than zero.');
        }

        $schema = $this->resolveVectorStore()->getSchema();

        foreach ($documents as $document) {
            $schema->validate($document);
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
