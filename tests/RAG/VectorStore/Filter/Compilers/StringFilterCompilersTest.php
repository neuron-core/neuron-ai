<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Filter\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Compilers\MeilisearchFilterCompiler;
use NeuronAI\RAG\VectorStore\Compilers\TypesenseFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Meilisearch and Typesense take filters as expression strings, so values
 * must be quoted in a way the server can never read as syntax.
 */
class StringFilterCompilersTest extends TestCase
{
    /**
     * @return array<string, array{FilterExpression, string, string}>
     */
    public static function operators(): array
    {
        return [
            'eq string' => [Filter::eq('lang', 'en'), "lang = 'en'", 'lang:=`en`'],
            'eq integer' => [Filter::eq('year', 2026), 'year = 2026', 'year:=2026'],
            'eq float' => [Filter::eq('price', 9.75), 'price = 9.75', 'price:=9.75'],
            'eq true' => [Filter::eq('draft', true), 'draft = true', 'draft:=true'],
            'eq false' => [Filter::eq('draft', false), 'draft = false', 'draft:=false'],
            'neq' => [Filter::neq('lang', 'de'), "lang != 'de'", 'lang:!=`de`'],
            'in mixed scalars' => [Filter::in('code', ['a', 1, true]), "code IN ['a', 1, true]", 'code:=[`a`, 1, true]'],
            'gt negative' => [Filter::gt('year', -5), 'year > -5', 'year:>-5'],
            'gte' => [Filter::gte('year', 2020), 'year >= 2020', 'year:>=2020'],
            'lt' => [Filter::lt('price', 0.5), 'price < 0.5', 'price:<0.5'],
            'lte' => [Filter::lte('price', 10), 'price <= 10', 'price:<=10'],
            'contains any' => [Filter::containsAny('tags', ['php', 'rag']), "tags IN ['php', 'rag']", 'tags:=[`php`, `rag`]'],
            'contains all' => [Filter::containsAll('tags', ['php', 'rag']), "(tags = 'php' AND tags = 'rag')", '(tags:=`php` && tags:=`rag`)'],
            'root disjunction is unwrapped' => [
                FilterGroup::anyOf(Filter::eq('a', 1), Filter::eq('b', 2)),
                'a = 1 OR b = 2',
                'a:=1 || b:=2',
            ],
            'nested groups keep parentheses' => [
                FilterGroup::anyOf(Filter::eq('a', 1), FilterGroup::allOf(Filter::eq('b', 2), FilterGroup::anyOf(Filter::eq('c', 3), Filter::eq('d', 4)))),
                'a = 1 OR (b = 2 AND (c = 3 OR d = 4))',
                'a:=1 || (b:=2 && (c:=3 || d:=4))',
            ],
            'containment inside a disjunction stays grouped' => [
                FilterGroup::anyOf(Filter::containsAll('tags', ['a', 'b']), Filter::eq('x', 1)),
                "(tags = 'a' AND tags = 'b') OR x = 1",
                '(tags:=`a` && tags:=`b`) || x:=1',
            ],
        ];
    }

    #[DataProvider('operators')]
    public function test_compiles_every_operator(FilterExpression $filters, string $meilisearch, string $typesense): void
    {
        $this->assertSame($meilisearch, (new MeilisearchFilterCompiler())->compile($filters));
        $this->assertSame($typesense, (new TypesenseFilterCompiler())->compile($filters));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function meilisearchQuoting(): array
    {
        return [
            'single quote' => ["o'hara", "'o\\'hara'"],
            'quote breakout' => ["x' OR sourceType = 'web", "'x\\' OR sourceType = \\'web'"],
            'backslash before quote' => ["x\\' OR 1 = 1", "'x\\\\\\' OR 1 = 1'"],
            'trailing backslash' => ['path\\', "'path\\\\'"],
            'double quotes stay literal' => ['say "hi"', "'say \"hi\"'"],
            'filter keywords stay literal' => ['a AND b OR NOT c', "'a AND b OR NOT c'"],
            'brackets and commas stay literal' => ['[1, 2]', "'[1, 2]'"],
            'multibyte' => ['Città 🌍', "'Città 🌍'"],
            'empty string' => ['', "''"],
        ];
    }

    #[DataProvider('meilisearchQuoting')]
    public function test_meilisearch_quotes_strings_so_they_cannot_close_the_literal(string $value, string $literal): void
    {
        $compiler = new MeilisearchFilterCompiler();

        $this->assertSame("tenant = {$literal}", $compiler->compile(Filter::eq('tenant', $value)));
        $this->assertSame("tenant IN [{$literal}]", $compiler->compile(Filter::in('tenant', [$value])));
        $this->assertSame("tags IN [{$literal}]", $compiler->compile(Filter::containsAny('tags', [$value])));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function typesenseLiteralValues(): array
    {
        return [
            'logical operators' => ['a && b || c'],
            'closing bracket and comma' => ['x], other:=[y'],
            'parentheses' => ['(x)'],
            'colon' => ['a:=b'],
            'quotes' => ['o\'hara "x"'],
            'backslash' => ['a\\b'],
            'multibyte' => ['Città 🌍'],
        ];
    }

    #[DataProvider('typesenseLiteralValues')]
    public function test_typesense_wraps_special_characters_in_backticks(string $value): void
    {
        $this->assertSame("tenant:=`{$value}`", (new TypesenseFilterCompiler())->compile(Filter::eq('tenant', $value)));
    }

    /**
     * @return array<string, array{FilterExpression}>
     */
    public static function backtickValues(): array
    {
        return [
            'eq' => [Filter::eq('tenant', 'ac`me')],
            'neq' => [Filter::neq('tenant', '`')],
            'in' => [Filter::in('tenant', ['ok', 'x` || tenant:=`y'])],
            'contains any' => [Filter::containsAny('tags', ['ok', 'x`'])],
            'contains all' => [Filter::containsAll('tags', ['`x'])],
            'nested' => [FilterGroup::anyOf(Filter::eq('a', 1), FilterGroup::allOf(Filter::eq('tenant', 'a`b')))],
        ];
    }

    #[DataProvider('backtickValues')]
    public function test_typesense_refuses_backticks_wherever_they_appear(FilterExpression $filters): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Typesense filter values cannot contain backticks without changing their meaning.');

        (new TypesenseFilterCompiler())->compile($filters);
    }

    public function test_each_compiler_passes_its_own_raw_fragment_through_in_parentheses(): void
    {
        $this->assertSame(
            "sourceType = 'file' OR (_geoRadius(45.4, 9.1, 2000))",
            (new MeilisearchFilterCompiler())->compile(FilterGroup::anyOf(
                Filter::eq('sourceType', 'file'),
                Filter::raw(MeilisearchVectorStore::class, '_geoRadius(45.4, 9.1, 2000)'),
            )),
        );
        $this->assertSame(
            'sourceType:=`file` || (location:(48.8, 2.3, 5 km))',
            (new TypesenseFilterCompiler())->compile(FilterGroup::anyOf(
                Filter::eq('sourceType', 'file'),
                Filter::raw(TypesenseVectorStore::class, 'location:(48.8, 2.3, 5 km)'),
            )),
        );
    }

    public function test_meilisearch_refuses_a_typesense_raw_fragment(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage(
            'Raw filter targets ' . TypesenseVectorStore::class . '; it cannot be compiled for ' . MeilisearchVectorStore::class . '.'
        );

        (new MeilisearchFilterCompiler())->compile(FilterGroup::allOf(
            Filter::eq('a', 1),
            Filter::raw(TypesenseVectorStore::class, 'a:=1'),
        ));
    }

    public function test_typesense_refuses_a_meilisearch_raw_fragment(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage(
            'Raw filter targets ' . MeilisearchVectorStore::class . '; it cannot be compiled for ' . TypesenseVectorStore::class . '.'
        );

        (new TypesenseFilterCompiler())->compile(Filter::raw(MeilisearchVectorStore::class, 'a = 1'));
    }
}
