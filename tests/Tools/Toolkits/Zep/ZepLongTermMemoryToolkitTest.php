<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Zep;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Zep\ZepLongTermMemoryToolkit;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class ZepLongTermMemoryToolkitTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_building_the_tools_sends_no_request(): void
    {
        $client = $this->recordingClient();

        ZepLongTermMemoryToolkit::make('zep-key', 'user-1', httpClient: $client)->tools();

        $this->assertSame([], $this->sentRequests);
    }

    public function test_a_call_ensures_the_user_before_searching_the_graph(): void
    {
        $client = $this->recordingClient(
            new Response(200, [], json_encode(['user_id' => 'user-1'])),
            new Response(200, [], json_encode(['edges' => [['fact' => 'Likes PHP', 'created_at' => '2026-09-24']]])),
        );
        [$search] = ZepLongTermMemoryToolkit::make('zep-key', 'user-1', httpClient: $client)->tools();

        $search->setInputs(['query' => 'preferences', 'search_scope' => 'facts'])->execute();

        $this->assertSame([
            'GET https://api.getzep.com/api/v2/users/user-1',
            'POST https://api.getzep.com/api/v2/graph/search',
        ], $this->sentTargets());
        foreach ($this->sentRequests as $entry) {
            $this->assertSame('Api-Key zep-key', $entry['request']->getHeaderLine('Authorization'));
        }
        $this->assertSame([['fact' => 'Likes PHP', 'created_at' => '2026-09-24']], json_decode((string) $search->getResult(), true));
    }

    public function test_a_missing_user_is_created_before_adding_to_the_graph(): void
    {
        $client = $this->recordingClient(
            new Response(404, [], json_encode(['message' => 'not found'])),
            new Response(201, [], json_encode(['user_id' => 'user-1'])),
            new Response(200, [], json_encode(['content' => 'Stored'])),
        );
        [, $add] = ZepLongTermMemoryToolkit::make('zep-key', 'user-1', httpClient: $client)->tools();

        $add->setInputs(['data' => 'Likes PHP', 'type' => 'text'])->execute();

        $this->assertSame([
            'GET https://api.getzep.com/api/v2/users/user-1',
            'POST https://api.getzep.com/api/v2/users',
            'POST https://api.getzep.com/api/v2/graph',
        ], $this->sentTargets());
        $this->assertSame(['user_id' => 'user-1'], json_decode((string) $this->sentRequests[1]['request']->getBody(), true));
        $this->assertSame('Stored', (string) $add->getResult());
    }
}
