<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Observability\PostProcessed;
use NeuronAI\RAG\Observability\PostProcessing;
use NeuronAI\RAG\Observability\PreProcessed;
use NeuronAI\RAG\Observability\PreProcessing;
use NeuronAI\RAG\Observability\Retrieved;
use NeuronAI\RAG\Observability\Retrieving;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\RAG\Stub\LimitPostProcessor;
use NeuronAI\Tests\RAG\Stub\SuffixPreProcessor;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function json_encode;
use function str_starts_with;

use const JSON_THROW_ON_ERROR;

class RAGObservabilityTest extends TestCase
{
    /** @var ObservabilityEvent[] */
    protected array $events = [];

    protected function observe(RAG $rag): RAG
    {
        return $rag->subscribe(ObservabilityEvent::class, function (ObservabilityEvent $event): void {
            if (str_starts_with($event->name(), 'rag-')) {
                $this->events[] = $event;
            }
        });
    }

    /**
     * @template T of ObservabilityEvent
     * @param class-string<T> $class
     * @return T
     */
    protected function event(string $class): ObservabilityEvent
    {
        foreach ($this->events as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        $this->fail("{$class} was not emitted.");
    }

    /**
     * @param Document[] $documents
     * @return string[]
     */
    protected function contents(array $documents): array
    {
        return array_map(static fn (Document $document): string => $document->getContent(), $documents);
    }

    public function test_the_retrieval_chain_emits_its_events_in_order_with_their_payloads(): void
    {
        $documents = [new Document('First'), new Document('Second'), new Document('First')];
        $preProcessor = new SuffixPreProcessor(' rewritten');
        $postProcessor = new LimitPostProcessor(1);
        $rag = RAG::make()
            ->setEmbeddingsProvider(new FakeEmbeddingsProvider())
            ->setVectorStore(new FakeVectorStore($documents))
            ->setPreProcessors([$preProcessor])
            ->setPostProcessors([$postProcessor]);
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));
        $question = new UserMessage('Question');

        $this->observe($rag)->chat($question);

        $this->assertSame(
            ['rag-preprocessing', 'rag-preprocessed', 'rag-retrieving', 'rag-retrieved', 'rag-postprocessing', 'rag-postprocessed'],
            array_map(static fn (ObservabilityEvent $event): string => $event->name(), $this->events),
        );

        $this->assertSame(
            ['processor' => SuffixPreProcessor::class, 'original' => $question->jsonSerialize()],
            $this->event(PreProcessing::class)->toArray(),
        );
        $rewritten = $this->event(PreProcessed::class)->processed;
        $this->assertSame('Question rewritten', $rewritten->getContent());
        $this->assertSame(
            ['processor' => SuffixPreProcessor::class, 'processed' => $rewritten->jsonSerialize()],
            $this->event(PreProcessed::class)->toArray(),
        );
        $this->assertSame(['question' => $rewritten->jsonSerialize(), 'filters' => null], $this->event(Retrieving::class)->toArray());

        $retrieved = $this->event(Retrieved::class)->toArray();
        $this->assertSame(['question', 'documents'], array_keys($retrieved));
        $this->assertSame($rewritten->jsonSerialize(), $retrieved['question']);
        $this->assertSame(['First', 'Second'], $this->contents($retrieved['documents']));

        $postProcessing = $this->event(PostProcessing::class)->toArray();
        $this->assertSame(['processor', 'question', 'documents'], array_keys($postProcessing));
        $this->assertSame(LimitPostProcessor::class, $postProcessing['processor']);
        $this->assertSame($rewritten->jsonSerialize(), $postProcessing['question']);
        $this->assertSame(['First', 'Second'], $this->contents($postProcessing['documents']));

        $postProcessed = $this->event(PostProcessed::class)->toArray();
        $this->assertSame(['processor', 'question', 'documents'], array_keys($postProcessed));
        $this->assertSame(LimitPostProcessor::class, $postProcessed['processor']);
        $this->assertSame($rewritten->jsonSerialize(), $postProcessed['question']);
        $this->assertSame(['First'], $this->contents($postProcessed['documents']));
    }

    public function test_without_processors_only_retrieval_events_are_emitted(): void
    {
        $rag = RAG::make()->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore(new FakeVectorStore());
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));

        $this->observe($rag)->chat(new UserMessage('Question'));

        $this->assertSame(
            ['rag-retrieving', 'rag-retrieved'],
            array_map(static fn (ObservabilityEvent $event): string => $event->name(), $this->events),
        );
        $this->assertSame([], $this->event(Retrieved::class)->toArray()['documents']);
    }

    public function test_retrieval_filter_values_never_reach_the_retrieving_payload(): void
    {
        $store = new MemoryVectorStore(schema: DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('clearance')->filterable(),
        ));
        $rag = RAG::make()->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($store)
            ->setRetrievalScope(FilterGroup::and(
                Filter::eq('tenant', 'tenant-secret-7f3a'),
                FilterGroup::anyOf(Filter::lte('clearance', 918273), Filter::in('sourceName', ['private-doc.md'])),
            ));
        $rag->setAiProvider(new FakeAIProvider(new AssistantMessage('Answer')));

        $this->observe($rag)->chat(new UserMessage('Question'));

        $payload = $this->event(Retrieving::class)->toArray();
        $this->assertSame([
            'operator' => 'and',
            'conditions' => [
                ['operator' => 'eq', 'field' => 'tenant'],
                ['operator' => 'or', 'conditions' => [
                    ['operator' => 'lte', 'field' => 'clearance'],
                    ['operator' => 'in', 'field' => 'sourceName'],
                ]],
            ],
        ], $payload['filters']);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('tenant-secret-7f3a', $json);
        $this->assertStringNotContainsString('918273', $json);
        $this->assertStringNotContainsString('private-doc.md', $json);
    }

    public function test_raw_filter_fragments_never_reach_the_retrieving_payload(): void
    {
        $event = new Retrieving(
            new UserMessage('Question'),
            FilterGroup::and(Filter::raw(MemoryVectorStore::class, "tenant_id = 'raw-secret-42'"), Filter::eq('lang', 'en')),
        );

        $this->assertSame([
            'operator' => 'and',
            'conditions' => [
                ['operator' => 'raw', 'store' => MemoryVectorStore::class],
                ['operator' => 'eq', 'field' => 'lang'],
            ],
        ], $event->toArray()['filters']);
        $this->assertStringNotContainsString('raw-secret-42', json_encode($event->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_an_unknown_filter_expression_is_reported_by_class_only(): void
    {
        $custom = new class () implements FilterExpression {
            public function toArray(): array
            {
                return ['secret' => 'custom-secret'];
            }
        };

        $payload = (new Retrieving(new UserMessage('Question'), $custom))->toArray();

        $this->assertSame(['operator' => 'unsupported', 'class' => $custom::class], $payload['filters']);
    }

    public function test_event_names_are_stable(): void
    {
        $question = new UserMessage('Question');

        $this->assertSame('rag-preprocessing', (new PreProcessing('processor', $question))->name());
        $this->assertSame('rag-preprocessed', (new PreProcessed('processor', $question))->name());
        $this->assertSame('rag-retrieving', (new Retrieving($question))->name());
        $this->assertSame('rag-retrieved', (new Retrieved($question, []))->name());
        $this->assertSame('rag-postprocessing', (new PostProcessing('processor', $question, []))->name());
        $this->assertSame('rag-postprocessed', (new PostProcessed('processor', $question, []))->name());
    }
}
