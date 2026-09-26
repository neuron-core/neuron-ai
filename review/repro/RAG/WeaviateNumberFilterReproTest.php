<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\WeaviateVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;

class WeaviateNumberFilterReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    protected function store(): WeaviateVectorStore
    {
        return new WeaviateVectorStore(
            collection: 'articles',
            httpClient: $this->recordingClient(
                $this->jsonResponse(['classes' => [['class' => 'Articles']]]),
                $this->jsonResponse(['data' => ['Get' => ['Articles' => []]]]),
            ),
            schema: DocumentSchema::of(
                DocumentField::float('price')->filterable(),
                DocumentField::integer('year')->filterable(),
            ),
        );
    }

    public function test_whole_number_search_filter_on_a_number_property_uses_value_number(): void
    {
        $this->store()->search(new SearchRequest([1.0], Filter::gt('price', 10)));

        $query = $this->sentJson(1)['query'];
        $this->assertStringContainsString('where: {path: ["price"], operator: GreaterThan, valueNumber: 10}', $query);
    }

    public function test_mixed_numeric_list_on_a_number_property_uses_value_number_array(): void
    {
        $this->store()->search(new SearchRequest([1.0], Filter::in('price', [1, 2.5])));

        $this->assertStringContainsString('valueNumberArray: [1, 2.5]', $this->sentJson(1)['query']);
    }

    public function test_whole_number_delete_filter_on_a_number_property_uses_value_number(): void
    {
        $this->store()->delete(Filter::eq('price', 10));

        $this->assertSame(
            ['path' => ['price'], 'operator' => 'Equal', 'valueNumber' => 10],
            $this->sentJson(1)['match']['where'],
        );
    }

    public function test_integer_property_keeps_value_int(): void
    {
        $this->store()->search(new SearchRequest([1.0], Filter::gt('year', 2020)));

        $this->assertStringContainsString('valueInt: 2020', $this->sentJson(1)['query']);
    }
}
