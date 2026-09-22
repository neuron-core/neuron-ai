<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\RAG\PreProcessor\PreProcessorInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
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
}
