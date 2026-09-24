<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Jina;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use PHPUnit\Framework\TestCase;

class JinaToolkitTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_tools_send_their_requests_through_the_injected_client(): void
    {
        $client = $this->recordingClient(new Response(200, [], 'Search results'), new Response(200, [], '# Page'));
        [$search, $reader] = JinaToolkit::make('jina-key', httpClient: $client)->tools();

        $search->setInputs(['search_query' => 'neuron ai'])->execute();
        $reader->setInputs(['url' => 'https://example.com'])->execute();

        $this->assertSame(['POST https://s.jina.ai/', 'POST https://r.jina.ai/'], $this->sentTargets());
        foreach ($this->sentRequests as $entry) {
            $this->assertSame('Bearer jina-key', $entry['request']->getHeaderLine('Authorization'));
        }
        $this->assertSame('no-content', $this->sentRequests[0]['request']->getHeaderLine('X-Respond-With'));
        $this->assertSame('Markdown', $this->sentRequests[1]['request']->getHeaderLine('X-Return-Format'));
        $this->assertSame('Search results', (string) $search->getResult());
        $this->assertSame('# Page', (string) $reader->getResult());
    }
}
