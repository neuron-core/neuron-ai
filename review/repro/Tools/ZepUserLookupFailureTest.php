<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Zep;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Zep\ZepAddToGraphTool;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ZepUserLookupFailureTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_only_a_missing_user_triggers_user_creation(): void
    {
        $tool = new ZepAddToGraphTool('zep-key', 'user-1', $this->recordingClient(
            new Response(503, [], json_encode(['message' => 'service unavailable'])),
            new Response(201, [], json_encode(['user_id' => 'user-1'])),
            new Response(202, [], json_encode(['content' => 'Stored'])),
        ));

        try {
            $tool('Likes PHP', 'text');
            $this->fail('A failing user lookup should not be treated as a missing user.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->response?->statusCode);
        }

        $this->assertSame(['GET https://api.getzep.com/api/v2/users/user-1'], $this->sentTargets());
    }

    public function test_a_missing_user_is_created_before_adding_data(): void
    {
        $tool = new ZepAddToGraphTool('zep-key', 'user-1', $this->recordingClient(
            new Response(404, [], json_encode(['message' => 'not found'])),
            new Response(201, [], json_encode(['user_id' => 'user-1'])),
            new Response(202, [], json_encode(['content' => 'Stored'])),
        ));

        $this->assertSame('Stored', $tool('Likes PHP', 'text'));
        $this->assertSame([
            'GET https://api.getzep.com/api/v2/users/user-1',
            'POST https://api.getzep.com/api/v2/users',
            'POST https://api.getzep.com/api/v2/graph',
        ], $this->sentTargets());
    }
}
