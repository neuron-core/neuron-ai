<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Compilers\MariaDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MariaDBFilterCompilerTest extends TestCase
{
    protected function compiler(): MariaDBFilterCompiler
    {
        return new MariaDBFilterCompiler(DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::float('price')->filterable(),
            DocumentField::boolean('draft')->filterable(),
            DocumentField::strings('tags')->filterable(),
        ));
    }

    /**
     * @return array<string, array{Filter, string, array<string, string|int|float>}>
     */
    public static function operators(): array
    {
        return [
            'eq on a column' => [Filter::eq('sourceType', 'file'), 'sourceType = :f0', [':f0' => 'file']],
            'neq on a column' => [Filter::neq('sourceName', 'a'), 'sourceName <> :f0', [':f0' => 'a']],
            'eq on string metadata' => [Filter::eq('tenant', 'acme'), "JSON_VALUE(metadata, '$.tenant') = :f0", [':f0' => 'acme']],
            'gt on integer metadata' => [Filter::gt('year', 2020), "CAST(JSON_VALUE(metadata, '$.year') AS SIGNED) > :f0", [':f0' => 2020]],
            'gte on integer metadata' => [Filter::gte('year', -1), "CAST(JSON_VALUE(metadata, '$.year') AS SIGNED) >= :f0", [':f0' => -1]],
            'lt on float metadata' => [Filter::lt('price', 9.5), "CAST(JSON_VALUE(metadata, '$.price') AS DECIMAL(65, 30)) < :f0", [':f0' => 9.5]],
            'lte on float metadata' => [Filter::lte('price', 0), "CAST(JSON_VALUE(metadata, '$.price') AS DECIMAL(65, 30)) <= :f0", [':f0' => 0]],
            'true on boolean metadata' => [Filter::eq('draft', true), "CAST(JSON_VALUE(metadata, '$.draft') AS UNSIGNED) = :f0", [':f0' => 1]],
            'false on boolean metadata' => [Filter::eq('draft', false), "CAST(JSON_VALUE(metadata, '$.draft') AS UNSIGNED) = :f0", [':f0' => 0]],
            'in' => [Filter::in('year', [2023, 2024]), "CAST(JSON_VALUE(metadata, '$.year') AS SIGNED) IN (:f0, :f1)", [':f0' => 2023, ':f1' => 2024]],
            'contains any' => [
                Filter::containsAny('tags', ['php', 'rag']),
                "(JSON_CONTAINS(metadata, JSON_QUOTE(:f0), '$.tags') OR JSON_CONTAINS(metadata, JSON_QUOTE(:f1), '$.tags'))",
                [':f0' => 'php', ':f1' => 'rag'],
            ],
            'contains all' => [
                Filter::containsAll('tags', ['php']),
                "(JSON_CONTAINS(metadata, JSON_QUOTE(:f0), '$.tags'))",
                [':f0' => 'php'],
            ],
        ];
    }

    /**
     * @param array<string, string|int|float> $bindings
     */
    #[DataProvider('operators')]
    public function test_compiles_every_operator_with_bound_values(Filter $filter, string $sql, array $bindings): void
    {
        $this->assertSame(['sql' => $sql, 'bindings' => $bindings], $this->compiler()->compile($filter));
    }

    public function test_root_disjunction_is_unwrapped_and_nested_groups_keep_parentheses(): void
    {
        $compiled = $this->compiler()->compile(FilterGroup::anyOf(
            Filter::eq('tenant', 'acme'),
            FilterGroup::allOf(Filter::eq('sourceType', 'web'), FilterGroup::anyOf(Filter::gt('year', 1), Filter::lt('year', 0))),
        ));

        $this->assertSame(
            "JSON_VALUE(metadata, '$.tenant') = :f0 OR (sourceType = :f1 AND " .
            "(CAST(JSON_VALUE(metadata, '$.year') AS SIGNED) > :f2 OR CAST(JSON_VALUE(metadata, '$.year') AS SIGNED) < :f3))",
            $compiled['sql'],
        );
        $this->assertSame([':f0' => 'acme', ':f1' => 'web', ':f2' => 1, ':f3' => 0], $compiled['bindings']);
    }

    public function test_placeholders_restart_for_every_compilation(): void
    {
        $compiler = $this->compiler();
        $compiler->compile(Filter::in('sourceType', ['a', 'b', 'c']));

        $this->assertSame(['sql' => 'sourceType = :f0', 'bindings' => [':f0' => 'x']], $compiler->compile(Filter::eq('sourceType', 'x')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileValues(): array
    {
        return [
            'quote breakout' => ["' OR '1'='1"],
            'comment' => ['x; DROP TABLE rag_documents; --'],
            'backslash escape' => ['\\\' OR 1=1 #'],
            'json path' => ['$.tenant'],
            'placeholder lookalike' => [':f1'],
            'null byte and unicode' => ["acme\0🌍"],
        ];
    }

    #[DataProvider('hostileValues')]
    public function test_values_never_reach_the_sql_text(string $value): void
    {
        $compiled = $this->compiler()->compile(FilterGroup::allOf(
            Filter::eq('tenant', $value),
            Filter::containsAny('tags', [$value]),
        ));

        $this->assertSame(
            "JSON_VALUE(metadata, '$.tenant') = :f0 AND (JSON_CONTAINS(metadata, JSON_QUOTE(:f1), '$.tags'))",
            $compiled['sql'],
        );
        $this->assertSame([':f0' => $value, ':f1' => $value], $compiled['bindings']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeFieldNames(): array
    {
        return [
            'quote breakout' => ["x') OR ('1'='1"],
            'space' => ['my field'],
            'json path traversal' => ['a.b'],
            'array path' => ['tags[0]'],
            'dash' => ['first-name'],
            'leading digit' => ['1field'],
            'dollar' => ['$where'],
            'backtick' => ['`x`'],
            'comment' => ['x--'],
            'multibyte' => ['città'],
        ];
    }

    #[DataProvider('unsafeFieldNames')]
    public function test_refuses_field_names_that_are_not_plain_identifiers(string $field): void
    {
        foreach ([Filter::eq($field, 'x'), Filter::containsAll($field, ['x'])] as $filter) {
            try {
                $this->compiler()->compile($filter);
                $this->fail("Field \"{$field}\" must be refused.");
            } catch (VectorStoreException $exception) {
                $this->assertSame("Metadata field \"{$field}\" is not a valid identifier for a SQL filter.", $exception->getMessage());
            }
        }
    }

    public function test_undeclared_metadata_fields_compare_as_json_text(): void
    {
        $this->assertSame(
            ['sql' => "JSON_VALUE(metadata, '$.custom_1') = :f0", 'bindings' => [':f0' => 'x']],
            (new MariaDBFilterCompiler())->compile(Filter::eq('custom_1', 'x')),
        );
    }

    public function test_passes_its_own_raw_fragment_through_in_parentheses(): void
    {
        $compiled = $this->compiler()->compile(FilterGroup::anyOf(
            Filter::eq('sourceType', 'file'),
            Filter::raw(MariaDBVectorStore::class, 'CHAR_LENGTH(content) > 10'),
        ));

        $this->assertSame(['sql' => 'sourceType = :f0 OR (CHAR_LENGTH(content) > 10)', 'bindings' => [':f0' => 'file']], $compiled);
    }

    public function test_refuses_a_raw_fragment_for_another_store_anywhere_in_the_tree(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage(
            'Raw filter targets ' . PineconeVectorStore::class . '; it cannot be compiled for ' . MariaDBVectorStore::class . '.'
        );

        $this->compiler()->compile(FilterGroup::allOf(
            Filter::eq('sourceType', 'file'),
            FilterGroup::anyOf(Filter::eq('tenant', 'a'), Filter::raw(PineconeVectorStore::class, '1=1')),
        ));
    }
}
