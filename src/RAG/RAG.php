<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\Agent\Agent;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Nodes\EnrichInstructionsNode;
use NeuronAI\RAG\Nodes\PostProcessDocumentsNode;
use NeuronAI\RAG\Nodes\PreProcessQueryNode;
use NeuronAI\RAG\Nodes\RetrieveDocumentsNode;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Node;

use function array_chunk;
use function array_keys;
use function array_merge;
use function explode;
use function is_array;

/**
 * @method static static make(?AIProviderInterface $aiProvider = null, ?string $workflowId = null)
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

    protected function startEvent(): Event
    {
        return new StartEvent();
    }

    /**
     * @param Node|Node[] $nodes Mode-specific nodes (ChatNode, StreamingNode, etc.)
     */
    protected function compose(array|Node $nodes): void
    {
        $nodes = is_array($nodes) ? $nodes : [$nodes];

        $nodes = array_merge($nodes, [
            new PreProcessQueryNode($this->preProcessors()),
            new RetrieveDocumentsNode($this->resolveRetrieval()),
            new PostProcessDocumentsNode($this->postProcessors()),
            new EnrichInstructionsNode($this->resolveInstructions(), $this->bootstrapTools()),
        ]);

        parent::compose($nodes);
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
            $this->resolveVectorStore()->deleteBySource($sourceType, $sourceName);
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
