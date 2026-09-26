<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\RAG\Stub\LimitPostProcessor;
use NeuronAI\Tests\RAG\Stub\SuffixPreProcessor;
use NeuronAI\Workflow\Observability\WorkflowStart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RAGConfigurationTest extends TestCase
{
    public function test_retrieval_dependencies_scope_and_processors_are_captured_per_segment(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First'), new AssistantMessage('Second'));
        $firstEmbeddings = new FakeEmbeddingsProvider();
        $nextEmbeddings = new FakeEmbeddingsProvider();
        $firstStore = new FakeVectorStore([new Document('Original context')]);
        $nextDocument = new Document('Next context');
        $nextStore = new FakeVectorStore([$nextDocument]);
        $firstScope = Filter::eq('sourceType', 'first');
        $nextScope = Filter::eq('sourceType', 'next');
        $preProcessor = $this->createMock(PreProcessorInterface::class);
        $preProcessor->expects(self::once())->method('process')->willReturn(new UserMessage('Rewritten query'));
        $postProcessor = $this->createMock(PostProcessorInterface::class);
        $postProcessor->expects(self::once())->method('process')
            ->with(self::isInstanceOf(UserMessage::class), [$nextDocument])
            ->willReturn([new Document('Processed context')]);
        $rag = RAG::make();
        $rag->setAiProvider($provider);
        $rag->setEmbeddingsProvider($firstEmbeddings)->setVectorStore($firstStore)->setRetrievalScope($firstScope);
        $changed = false;
        $rag->subscribe(WorkflowStart::class, function () use ($rag, &$changed, $nextEmbeddings, $nextStore, $nextScope, $preProcessor, $postProcessor): void {
            if ($changed) {
                return;
            }
            $changed = true;
            $rag->setEmbeddingsProvider($nextEmbeddings)->setVectorStore($nextStore)->setRetrievalScope($nextScope)
                ->setPreProcessors([$preProcessor])->setPostProcessors([$postProcessor]);
        });

        $rag->chat(new UserMessage('First question'));
        $firstEmbeddings->assertEmbeddedText('First question');
        $nextEmbeddings->assertNothingEmbedded();
        $firstStore->assertSearchCount(1);
        $firstStore->assertSearchedWithFilters($firstScope);
        $nextStore->assertSearchCount(0);
        self::assertStringContainsString('Original context', $provider->getRecorded()[0]->systemPrompt->getContent());

        $rag->chat(new UserMessage('Second question'));
        $firstEmbeddings->assertCallCount(1);
        $nextEmbeddings->assertEmbeddedText('Rewritten query');
        $firstStore->assertSearchCount(1);
        $nextStore->assertSearchCount(1);
        $nextStore->assertSearchedWithFilters($nextScope);
        self::assertStringContainsString('Processed context', $provider->getRecorded()[1]->systemPrompt->getContent());
        self::assertStringNotContainsString('Original context', $provider->getRecorded()[1]->systemPrompt->getContent());
    }

    public function test_explicit_retrieval_changes_apply_to_the_next_segment(): void
    {
        $first = $this->createMock(RetrievalInterface::class);
        $first->expects(self::once())->method('retrieve')->willReturn([new Document('Original strategy')]);
        $next = $this->createMock(RetrievalInterface::class);
        $next->expects(self::exactly(2))->method('retrieve')->willReturn([new Document('Next strategy')]);
        $provider = new FakeAIProvider(new AssistantMessage('One'), new AssistantMessage('Two'), new AssistantMessage('Three'));
        $rag = RAG::make()->setRetrieval($first);
        $rag->setAiProvider($provider);
        $rag->subscribe(WorkflowStart::class, static function () use ($rag, $next): void {
            $rag->setRetrieval($next);
        });

        $rag->chat(new UserMessage('First question'));
        $rag->setVectorStore(new FakeVectorStore())->setEmbeddingsProvider(new FakeEmbeddingsProvider());
        $rag->chat(new UserMessage('Second question'));
        $rag->chat(new UserMessage('Third question'));

        self::assertSame($next, $rag->resolveRetrieval());
        self::assertStringContainsString('Original strategy', $provider->getRecorded()[0]->systemPrompt->getContent());
        self::assertStringContainsString('Next strategy', $provider->getRecorded()[1]->systemPrompt->getContent());
        self::assertStringContainsString('Next strategy', $provider->getRecorded()[2]->systemPrompt->getContent());
    }

    public function test_configured_processors_win_over_the_declared_ones(): void
    {
        $declaredPre = $this->createMock(PreProcessorInterface::class);
        $declaredPre->expects(self::never())->method('process');
        $declaredPost = $this->createMock(PostProcessorInterface::class);
        $declaredPost->expects(self::never())->method('process');
        $configuredPre = $this->createMock(PreProcessorInterface::class);
        $configuredPre->expects(self::once())->method('process')->willReturnArgument(0);
        $configuredPost = $this->createMock(PostProcessorInterface::class);
        $configuredPost->expects(self::once())->method('process')->willReturnArgument(1);
        $rag = new class ($declaredPre, $declaredPost) extends RAG {
            public function __construct(protected PreProcessorInterface $declaredPre, protected PostProcessorInterface $declaredPost)
            {
                parent::__construct();
            }

            protected function preProcessors(): array
            {
                return [$this->declaredPre];
            }

            protected function postProcessors(): array
            {
                return [$this->declaredPost];
            }
        };
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore(new FakeVectorStore([new Document('Context')]))
            ->setPreProcessors([$configuredPre])->setPostProcessors([$configuredPost]);

        $rag->chat(new UserMessage('Question'));
    }

    public function test_setting_processors_replaces_the_previous_ones(): void
    {
        $replacedPre = $this->createMock(PreProcessorInterface::class);
        $replacedPre->expects(self::never())->method('process');
        $replacedPost = $this->createMock(PostProcessorInterface::class);
        $replacedPost->expects(self::never())->method('process');
        $currentPre = $this->createMock(PreProcessorInterface::class);
        $currentPre->expects(self::once())->method('process')->willReturnArgument(0);
        $currentPost = $this->createMock(PostProcessorInterface::class);
        $currentPost->expects(self::once())->method('process')->willReturnArgument(1);
        $rag = RAG::make();
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore(new FakeVectorStore([new Document('Context')]))
            ->setPreProcessors([$replacedPre])->setPreProcessors([$currentPre])
            ->setPostProcessors([$replacedPost])->setPostProcessors([$currentPost]);

        $rag->chat(new UserMessage('Question'));
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function processorSetters(): iterable
    {
        yield 'pre-processors' => ['setPreProcessors', PreProcessorInterface::class];
        yield 'post-processors' => ['setPostProcessors', PostProcessorInterface::class];
    }

    /** @param class-string $interface */
    #[DataProvider('processorSetters')]
    public function test_processor_setters_reject_values_that_are_not_processors(string $setter, string $interface): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('string must implement '.$interface);

        RAG::make()->{$setter}([$interface]);
    }

    public function test_processor_setters_reject_the_wrong_kind_of_processor_without_replacing_the_list(): void
    {
        $current = new LimitPostProcessor(1);
        $rag = RAG::make()->setPostProcessors([$current]);

        try {
            /** @phpstan-ignore-next-line deliberately wrong type */
            $rag->setPostProcessors([new LimitPostProcessor(2), new SuffixPreProcessor(' wrong')]);
            $this->fail('A pre-processor is not a post-processor.');
        } catch (AgentException $exception) {
            $this->assertSame(SuffixPreProcessor::class.' must implement '.PostProcessorInterface::class, $exception->getMessage());
        }

        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));
        $rag->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore(new FakeVectorStore([new Document('Context')]));
        $rag->chat(new UserMessage('Question'));
        $this->assertCount(1, $current->received);
    }

    public function test_the_pre_processor_setter_rejects_a_post_processor(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage(LimitPostProcessor::class.' must implement '.PreProcessorInterface::class);

        /** @phpstan-ignore-next-line deliberately wrong type */
        RAG::make()->setPreProcessors([new SuffixPreProcessor(' ok'), new LimitPostProcessor(1)]);
    }

    public function test_a_missing_embeddings_provider_fails_with_guidance(): void
    {
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('No embeddings provider configured: override the embeddings() method in your RAG agent, or call setEmbeddingsProvider().');

        RAG::make()->resolveEmbeddingsProvider();
    }

    public function test_a_turn_without_embeddings_provider_fails_before_inference(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Never sent'));
        $rag = RAG::make();
        $rag->setAiProvider($provider);

        try {
            $rag->chat(new UserMessage('Question'));
            $this->fail('A RAG turn needs an embeddings provider for the default retrieval.');
        } catch (AgentException $exception) {
            $this->assertStringStartsWith('No embeddings provider configured', $exception->getMessage());
        }

        $provider->assertNothingSent();
    }

    public function test_the_embeddings_hook_is_resolved_once_and_the_setter_wins(): void
    {
        $rag = new class () extends RAG {
            public int $resolutions = 0;

            protected function embeddings(): EmbeddingsProviderInterface
            {
                $this->resolutions++;

                return new FakeEmbeddingsProvider();
            }
        };

        $resolved = $rag->resolveEmbeddingsProvider();
        $this->assertSame($resolved, $rag->resolveEmbeddingsProvider());
        $this->assertSame(1, $rag->resolutions);

        $configured = new FakeEmbeddingsProvider();
        $this->assertSame($configured, $rag->setEmbeddingsProvider($configured)->resolveEmbeddingsProvider());
    }

    public function test_the_default_vector_store_is_one_in_memory_store(): void
    {
        $rag = RAG::make();

        $store = $rag->resolveVectorStore();

        $this->assertInstanceOf(MemoryVectorStore::class, $store);
        $this->assertSame($store, $rag->resolveVectorStore());
    }

    public function test_the_default_retrieval_searches_the_configured_store_with_the_configured_embeddings(): void
    {
        $embeddings = new FakeEmbeddingsProvider();
        $store = new FakeVectorStore();
        $rag = RAG::make()->setEmbeddingsProvider($embeddings)->setVectorStore($store);

        $retrieval = $rag->resolveRetrieval();
        $retrieval->retrieve(new UserMessage('Question'));

        $this->assertInstanceOf(SimilarityRetrieval::class, $retrieval);
        $embeddings->assertEmbeddedText('Question');
        $store->assertSearchCount(1);
    }

    public function test_an_explicit_retrieval_scope_wins_over_the_hook_until_cleared(): void
    {
        $hookScope = Filter::eq('sourceType', 'hook');
        $explicitScope = Filter::eq('sourceType', 'explicit');
        $rag = new class ($hookScope) extends RAG {
            public function __construct(protected FilterExpression $hookScope)
            {
                parent::__construct();
            }

            protected function retrievalScope(): FilterExpression
            {
                return $this->hookScope;
            }
        };

        $this->assertSame($hookScope, $rag->resolveRetrievalScope());
        $this->assertSame($explicitScope, $rag->setRetrievalScope($explicitScope)->resolveRetrievalScope());
        $this->assertSame($hookScope, $rag->setRetrievalScope(null)->resolveRetrievalScope());
    }

    public function test_no_retrieval_scope_by_default(): void
    {
        $this->assertNull(RAG::make()->resolveRetrievalScope());
    }
}
