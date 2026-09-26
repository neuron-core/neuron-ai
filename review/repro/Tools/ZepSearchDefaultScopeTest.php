<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Zep;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Zep\ZepSearchGraphTool;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class ZepSearchDefaultScopeTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_omitting_the_optional_search_scope_searches_facts(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient(
            new Response(200, [], json_encode(['user_id' => 'user-1'])),
            new Response(200, [], json_encode(['edges' => [['fact' => 'Likes PHP', 'created_at' => '2026-09-24']]])),
        ));

        $tool->setInputs(['query' => 'preferences'])->execute();

        $this->assertSame('edges', json_decode((string) $this->sentRequests[1]['request']->getBody(), true)['scope']);
        $this->assertSame([['fact' => 'Likes PHP', 'created_at' => '2026-09-24']], json_decode((string) $tool->getResult(), true));
    }
}
