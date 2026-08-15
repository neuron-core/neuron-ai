<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterEvaluator;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use PHPUnit\Framework\TestCase;

class FilterEvaluatorTest extends TestCase
{
    protected FilterEvaluator $evaluator;

    /**
     * @var array<string, mixed>
     */
    protected array $fields = [
        'sourceType' => 'file',
        'sourceName' => 'doc.txt',
        'year' => 2024,
        'reviewed' => true,
    ];

    protected function setUp(): void
    {
        $this->evaluator = new FilterEvaluator();
    }

    public function test_all_conditions_must_match(): void
    {
        $matching = FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::gte('year', 2020),
        );
        $failing = FilterGroup::and(
            Filter::eq('sourceType', 'file'),
            Filter::gte('year', 2025),
        );

        $this->assertTrue($this->evaluator->matches($matching, $this->fields));
        $this->assertFalse($this->evaluator->matches($failing, $this->fields));
    }

    public function test_neq_and_in(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::neq('sourceType', 'web')),
            $this->fields
        ));
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::in('sourceType', ['web', 'file'])),
            $this->fields
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::in('sourceType', ['web', 'api'])),
            $this->fields
        ));
    }

    public function test_range_operators(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::gt('year', 2023), Filter::lt('year', 2025)),
            $this->fields
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::lte('year', 2023)),
            $this->fields
        ));
    }

    public function test_missing_field_never_matches_even_neq(): void
    {
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::eq('tenant', 'acme')),
            $this->fields
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::neq('tenant', 'acme')),
            $this->fields
        ));
    }

    public function test_numeric_values_compare_loosely(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::eq('year', 2024.0)),
            $this->fields
        ));
    }

    public function test_boolean_values_compare_strictly(): void
    {
        $this->assertTrue($this->evaluator->matches(
            FilterGroup::and(Filter::eq('reviewed', true)),
            $this->fields
        ));
        $this->assertFalse($this->evaluator->matches(
            FilterGroup::and(Filter::eq('reviewed', false)),
            $this->fields
        ));
    }

    public function test_raw_filters_cannot_be_evaluated(): void
    {
        $this->expectException(VectorStoreException::class);

        $this->evaluator->matches(
            FilterGroup::and(Filter::raw(MeilisearchVectorStore::class, "sourceType = 'file'")),
            $this->fields
        );
    }
}
