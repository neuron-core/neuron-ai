<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Zep;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Zep\ZepSearchGraphTool;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ZepUserIdEncodingTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_the_user_id_is_encoded_as_a_single_path_segment(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', '../admin?x=1', $this->recordingClient(
            new Response(200, [], json_encode(['user_id' => '../admin?x=1'])),
            new Response(200, [], json_encode(['edges' => []])),
        ));

        $tool('anything', 'facts');

        $this->assertSame('GET https://api.getzep.com/api/v2/users/..%2Fadmin%3Fx%3D1', $this->sentTargets()[0]);
    }
}
