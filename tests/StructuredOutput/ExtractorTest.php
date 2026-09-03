<?php

declare(strict_types=1);

namespace NeuronAI\Tests\StructuredOutput;

use NeuronAI\StructuredOutput\JsonExtractor;
use PHPUnit\Framework\TestCase;

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

        $this->assertEquals($this->json, $result);
    }

    public function test_markdown(): void
    {
        $text = '```json'.$this->json.'```';
        $result = $this->extractor->getJson($text);
        $this->assertEquals($this->json, $result);

        $text = '```json'.PHP_EOL.$this->json.PHP_EOL.'```';
        $result = $this->extractor->getJson($text);
        $this->assertEquals($this->json, $result);

        $text = 'Here is the corresponding json structure:  ```json'.PHP_EOL.$this->json.PHP_EOL.'```';
        $result = $this->extractor->getJson($text);
        $this->assertEquals($this->json, $result);
    }

    public function test_unstructured_response(): void
    {
        $text = 'Here is the corresponding json structure: '.$this->json;
        $result = $this->extractor->getJson($text);
        $this->assertEquals($this->json, $result);
    }

    public function test_add_extractor_extends_built_in_ones(): void
    {
        $encoded = base64_encode($this->json);
        $this->assertNull($this->extractor->getJson($encoded));

        $this->extractor->addExtractor(fn (string $input): string => base64_decode($input));

        $this->assertEquals($this->json, $this->extractor->getJson($encoded));
        $this->assertEquals($this->json, $this->extractor->getJson($this->json));
    }

    public function test_set_extractors_replaces_built_in_ones(): void
    {
        $this->extractor->setExtractors([
            fn (string $input): array => ['{"replaced":true}'],
        ]);

        $this->assertEquals('{"replaced":true}', $this->extractor->getJson($this->json));
    }
}
