<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function base64_decode;
use function base64_encode;
use function json_decode;
use function str_repeat;

use const PHP_EOL;

class ExtractorTest extends TestCase
{
    protected JsonExtractor $extractor;

    protected string $json = '{"firstName":"John","lastName":"Doe"}';

    protected function setUp(): void
    {
        $this->extractor = new JsonExtractor();
    }

    public function test_json_string(): void
    {
        $result = $this->extractor->getJson($this->json);

        $this->assertSame($this->json, $result);
    }

    public function test_markdown(): void
    {
        $text = '```json'.$this->json.'```';
        $this->assertSame($this->json, $this->extractor->getJson($text));

        $text = '```json'.PHP_EOL.$this->json.PHP_EOL.'```';
        $this->assertSame($this->json, $this->extractor->getJson($text));

        $text = 'Here is the corresponding json structure:  ```json'.PHP_EOL.$this->json.PHP_EOL.'```';
        $this->assertSame($this->json, $this->extractor->getJson($text));
    }

    public function test_unstructured_response(): void
    {
        $text = 'Here is the corresponding json structure: '.$this->json;

        $this->assertSame($this->json, $this->extractor->getJson($text));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function embeddedJsonProvider(): array
    {
        return [
            'surrounding whitespace' => ["  \n\t{\"a\":1}\n  ", '{"a":1}'],
            'leading and trailing prose' => ['Sure! {"a":1} Hope this helps.', '{"a":1}'],
            'object at start followed by prose' => ["{\"a\":1}\n\nHope this helps!", '{"a":1}'],
            'object at start followed by prose with braces' => ["{\"a\":1}\n\nNote: use {placeholders}.", '{"a":1}'],
            'first of several fenced blocks wins' => ["```json\n{\"a\":1}\n```\ntext\n```json\n{\"b\":2}\n```", '{"a":1}'],
            'invalid fenced block skipped' => ["```json\n{not json}\n```\n```json\n{\"b\":2}\n```", '{"b":2}'],
            'fence without language tag' => ["Sure:\n```\n{\"a\":1}\n```\nDone", '{"a":1}'],
            'unclosed fence' => ["```json\n{\"a\":1}", '{"a":1}'],
            'fence with prose after the object inside the block' => ["```json\n{\"a\":1}\n// generated\n```", '{"a":1}'],
            'first of several objects in prose' => ['first {"a":1} second {"b":2}', '{"a":1}'],
            'invalid object before valid one' => ['{draft} then {"b":2}', '{"b":2}'],
            'braces inside string values' => ['Result: {"a":"}{","b":"x"} end', '{"a":"}{","b":"x"}'],
            'escaped quotes and braces inside strings' => ['Result: {"a":"he said \"hi\" {x}"} end', '{"a":"he said \"hi\" {x}"}'],
            'closing brace inside string when prose has braces' => ['Result: {"a":"}{","b":"x"} end {x}', '{"a":"}{","b":"x"}'],
            'escaped quote before brace inside string when prose has braces' => ['Answer: {"a":"say \\"}\\" now"} see {x}', '{"a":"say \\"}\\" now"}'],
            'fenced block wins over json in prose' => ["For input {\"q\":1} the answer is:\n```json\n{\"a\":2}\n```", '{"a":2}'],
            'nested fenced object wins over json in prose' => ["For input {\"q\":1} the answer is:\n```json\n{\"a\":{\"b\":2}}\n```", '{"a":{"b":2}}'],
            'later fenced block wins over json in prose when the first block is invalid' => ["Input {\"q\":1}\n```json\n{draft}\n```\n```json\n{\"b\":2}\n```", '{"b":2}'],
            'escaped backslash before closing quote' => ['Path: {"p":"C:\\\\"} and {x}', '{"p":"C:\\\\"}'],
            'nested objects' => ['Output: {"a":{"b":{"c":[1,2,{"d":null}]}}}', '{"a":{"b":{"c":[1,2,{"d":null}]}}}'],
            'top level list is kept' => ['[1,2]', '[1,2]'],
        ];
    }

    #[DataProvider('embeddedJsonProvider')]
    public function test_extracts_json_embedded_in_text(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->extractor->getJson($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noJsonProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ["  \n\t "],
            'plain prose' => ['I could not find any data.'],
            'truncated object' => ['Here {"a": 1, "b": [1,2'],
            'single quoted pseudo json' => ["{'a': 1}"],
            'trailing comma' => ['{"a":1,}'],
            'json scalar number' => ['42'],
            'json scalar string' => ['"abc"'],
            'json false' => ['false'],
            'json null' => ['null'],
            'only closing braces' => ['}}}'],
            'reversed braces' => ['} not json {'],
            'nesting beyond the decoder depth limit' => [str_repeat('[', 600).str_repeat(']', 600)],
        ];
    }

    #[DataProvider('noJsonProvider')]
    public function test_returns_null_when_no_json_object_can_be_parsed(string $input): void
    {
        $this->assertNull($this->extractor->getJson($input));
    }

    public function test_output_is_re_encoded_json_that_decodes_to_the_same_data(): void
    {
        $input = "Here you go:\n```json\n{\n  \"name\": \"J\u{00FC}rgen \u{1F680}\",\n  \"path\": \"a/b\",\n  \"ratio\": 0.25\n}\n```";

        $result = $this->extractor->getJson($input);

        $this->assertNotNull($result);
        $this->assertSame(
            ['name' => "J\u{00FC}rgen \u{1F680}", 'path' => 'a/b', 'ratio' => 0.25],
            json_decode($result, true)
        );
    }

    public function test_multibyte_text_around_the_json_does_not_break_extraction(): void
    {
        $this->assertSame(
            '{"city":"Z\u00fcrich"}',
            $this->extractor->getJson('Ecco il risultato è: {"city":"Zürich"} — grazie!')
        );
    }

    public function test_add_extractor_extends_built_in_ones(): void
    {
        $encoded = base64_encode($this->json);
        $this->assertNull($this->extractor->getJson($encoded));

        $this->extractor->addExtractor(fn (string $input): string => base64_decode($input));

        $this->assertSame($this->json, $this->extractor->getJson($encoded));
        $this->assertSame($this->json, $this->extractor->getJson($this->json));
    }

    public function test_add_extractor_runs_after_built_in_ones(): void
    {
        $this->extractor->addExtractor(fn (string $input): string => '{"custom":true}');

        $this->assertSame($this->json, $this->extractor->getJson($this->json));
    }

    public function test_add_extractor_accepts_invokable_objects(): void
    {
        $invokable = new class () {
            public function __invoke(string $input): string
            {
                return '{"invoked":true}';
            }
        };

        $this->extractor->setExtractors([])->addExtractor($invokable);

        $this->assertSame('{"invoked":true}', $this->extractor->getJson('anything'));
    }

    public function test_set_extractors_replaces_built_in_ones(): void
    {
        $this->extractor->setExtractors([
            fn (string $input): array => ['{"replaced":true}'],
        ]);

        $this->assertSame('{"replaced":true}', $this->extractor->getJson($this->json));
    }

    public function test_without_extractors_nothing_is_extracted(): void
    {
        $this->extractor->setExtractors([]);

        $this->assertNull($this->extractor->getJson($this->json));
    }

    public function test_extractors_run_in_order_and_first_parsable_candidate_wins(): void
    {
        $calls = [];

        $this->extractor->setExtractors([
            function (string $input) use (&$calls): ?string {
                $calls[] = 'empty';
                return null;
            },
            function (string $input) use (&$calls): array {
                $calls[] = 'candidates';
                return ['', '   ', 'not json', new stdClass(), '{"second":2}', '{"third":3}'];
            },
            function (string $input) use (&$calls): string {
                $calls[] = 'never';
                return '{"never":true}';
            },
        ]);

        $this->assertSame('{"second":2}', $this->extractor->getJson('input'));
        $this->assertSame(['empty', 'candidates'], $calls);
    }
}
