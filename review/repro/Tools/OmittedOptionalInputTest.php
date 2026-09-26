<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\Zep\ZepSearchGraphTool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class OmittedOptionalInputTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_an_omitted_optional_input_falls_back_to_the_invoke_default(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'search';

            protected function properties(): array
            {
                return [
                    new ToolProperty('query', PropertyType::STRING, 'Query', true),
                    new ToolProperty('limit', PropertyType::INTEGER, 'Max results', false),
                ];
            }

            public function __invoke(string $query, int $limit = 10): string
            {
                return "{$query}:{$limit}";
            }
        };

        $tool->setInputs(['query' => 'php'])->execute();

        $this->assertSame('php:10', $tool->getResult());
    }

    public function test_zep_search_without_the_optional_scope_searches_facts(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient(
            new Response(200, [], (string) json_encode(['uuid' => 'user-1'])),
            new Response(200, [], (string) json_encode(['edges' => []])),
        ));

        $tool->setInputs(['query' => 'favourite language'])->execute();

        $this->assertSame(
            ['user_id' => 'user-1', 'query' => 'favourite language', 'scope' => 'edges', 'limit' => 5],
            json_decode((string) $this->sentRequests[1]['request']->getBody(), true)
        );
    }
}
