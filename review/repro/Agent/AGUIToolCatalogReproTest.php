<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Frontend;

use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Exceptions\InputTranslationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AGUIToolCatalogReproTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsupportedSchemas(): iterable
    {
        yield 'unknown property type' => [['type' => 'object', 'properties' => ['x' => ['type' => 'unknown']]]];
        yield 'numeric property type' => [['type' => 'object', 'properties' => ['x' => ['type' => 5]]]];
        yield 'property definition is a string' => [['type' => 'object', 'properties' => ['x' => 'string']]];
        yield 'properties is a list' => [['type' => 'object', 'properties' => [['type' => 'string']]]];
        yield 'properties is a string' => [['type' => 'object', 'properties' => 'x']];
        yield 'required is a string' => [['type' => 'object', 'required' => 'x', 'properties' => ['x' => ['type' => 'string']]]];
        yield 'items is a string' => [['type' => 'object', 'properties' => ['x' => ['type' => 'array', 'items' => 'string']]]];
        yield 'unsupported keyword' => [['type' => 'object', 'properties' => ['x' => ['$ref' => '#/defs/x']]]];
        yield 'union keyword' => [['type' => 'object', 'properties' => ['x' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]]]];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('unsupportedSchemas')]
    public function test_an_unsupported_client_schema_is_an_input_translation_error(array $parameters): void
    {
        $this->expectException(InputTranslationException::class);
        $this->expectExceptionMessageMatches("/'browser'/");

        (new AGUIInputTranslator())->tools(['tools' => [
            ['name' => 'browser', 'description' => 'Read the page', 'parameters' => $parameters],
        ]]);
    }
}
