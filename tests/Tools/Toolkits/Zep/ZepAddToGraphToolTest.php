<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Zep;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Zep\ZepAddToGraphTool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class ZepAddToGraphToolTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_an_existing_user_is_not_created_again(): void
    {
        $tool = new ZepAddToGraphTool('zep-key', 'user-1', $this->recordingClient(
            new Response(200, [], json_encode(['user_id' => 'user-1'])),
            new Response(202, [], json_encode(['content' => 'Stored'])),
        ));

        $tool->setInputs(['data' => 'Likes PHP', 'type' => 'text'])->execute();

        $this->assertSame([
            'GET https://api.getzep.com/api/v2/users/user-1',
            'POST https://api.getzep.com/api/v2/graph',
        ], $this->sentTargets());
        $this->assertSame('Stored', (string) $tool->getResult());
    }

    public function test_the_data_is_posted_for_the_configured_user_only(): void
    {
        $data = "{\"user_id\": \"someone-else\"}\nÜnïcode ☕";
        $tool = new ZepAddToGraphTool('zep-key', 'user-1', $this->recordingClient(
            new Response(200, [], json_encode(['user_id' => 'user-1'])),
            new Response(202, [], json_encode(['content' => $data])),
        ));

        $tool($data, 'json');

        $this->assertSame(
            ['user_id' => 'user-1', 'data' => $data, 'type' => 'json'],
            json_decode((string) $this->sentRequests[1]['request']->getBody(), true)
        );
    }

    public function test_the_model_must_provide_data_and_a_supported_type(): void
    {
        $tool = new ZepAddToGraphTool('zep-key', 'user-1', $this->recordingClient());

        $this->assertSame(['data', 'type'], $tool->getRequiredProperties());
        $type = $tool->getProperties()[1];
        $this->assertInstanceOf(ToolProperty::class, $type);
        $this->assertSame(['text', 'json', 'message'], $type->getEnum());
    }
}
