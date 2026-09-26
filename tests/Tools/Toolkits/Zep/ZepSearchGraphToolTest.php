<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\Zep;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tests\Support\AssertsApiKeyConfinement;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Zep\ZepSearchGraphTool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

class ZepSearchGraphToolTest extends TestCase
{
    use AssertsApiKeyConfinement;
    use RecordsHttpRequests;

    public function test_searching_facts_queries_the_edges_scope(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient($this->existingUser(), $this->json(['edges' => []])));

        $tool('favourite language', 'facts');

        $this->assertSame([
            'GET https://api.getzep.com/api/v2/users/user-1',
            'POST https://api.getzep.com/api/v2/graph/search',
        ], $this->sentTargets());
        $this->assertSame(
            ['user_id' => 'user-1', 'query' => 'favourite language', 'scope' => 'edges', 'limit' => 5],
            json_decode((string) $this->sentRequests[1]['request']->getBody(), true)
        );
    }

    public function test_a_hostile_query_is_sent_verbatim_and_cannot_change_the_user(): void
    {
        $query = "  \"}, \"user_id\": \"someone-else\", \"limit\": 1000, \"x\": {\"\ncaffè ☕\n";
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient($this->existingUser(), $this->json(['edges' => []])));

        $tool($query, 'facts');

        $this->assertSame(
            ['user_id' => 'user-1', 'query' => $query, 'scope' => 'edges', 'limit' => 5],
            json_decode((string) $this->sentRequests[1]['request']->getBody(), true)
        );
    }

    public function test_facts_are_reduced_to_fact_and_creation_date(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient($this->existingUser(), $this->json(['edges' => [
            ['uuid' => 'e-1', 'fact' => 'Likes PHP', 'created_at' => '2026-09-24T10:00:00Z', 'valid_at' => null],
            ['uuid' => 'e-2', 'fact' => 'Lives in Rome', 'created_at' => '2026-09-25T10:00:00Z'],
        ]])));

        $this->assertSame([
            ['fact' => 'Likes PHP', 'created_at' => '2026-09-24T10:00:00Z'],
            ['fact' => 'Lives in Rome', 'created_at' => '2026-09-25T10:00:00Z'],
        ], $tool('about the user', 'facts'));
    }

    public function test_searching_nodes_returns_name_and_summary(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient($this->existingUser(), $this->json(['nodes' => [
            ['uuid' => 'n-1', 'name' => 'Neuron', 'summary' => 'A PHP framework', 'labels' => ['Entity']],
        ]])));

        $result = $tool('frameworks', 'nodes');

        $this->assertSame([['name' => 'Neuron', 'summary' => 'A PHP framework']], $result);
        $this->assertSame('nodes', json_decode((string) $this->sentRequests[1]['request']->getBody(), true)['scope']);
    }

    public function test_a_response_without_matches_yields_an_empty_list(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient(
            $this->existingUser(),
            $this->json([]),
            $this->existingUser(),
            $this->json([]),
        ));

        $this->assertSame([], $tool('anything', 'facts'));
        $this->assertSame([], $tool('anything', 'nodes'));
    }

    public function test_the_model_can_only_pick_facts_or_nodes(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient());

        $this->assertSame(['query'], $tool->getRequiredProperties());
        $scope = $tool->getProperties()[1];
        $this->assertInstanceOf(ToolProperty::class, $scope);
        $this->assertSame(['facts', 'nodes'], $scope->getEnum());
    }

    public function test_every_request_authenticates_with_the_api_key_header(): void
    {
        $tool = new ZepSearchGraphTool('zep-key', 'user-1', $this->recordingClient($this->existingUser(), $this->json(['edges' => []])));

        $tool('anything', 'facts');

        foreach ($this->sentRequests as $entry) {
            $this->assertSame('Api-Key zep-key', $entry['request']->getHeaderLine('Authorization'));
            $this->assertApiKeyTravelsOnlyIn('Authorization', 'zep-key', $entry['request']);
            $this->assertSame('application/json', $entry['request']->getHeaderLine('Accept'));
        }
    }

    public function test_a_failing_search_raises_an_http_exception_without_the_api_key(): void
    {
        $tool = new ZepSearchGraphTool('secret-zep-key', 'user-1', $this->recordingClient(
            $this->existingUser(),
            new Response(500, [], '{"message":"internal error"}'),
        ));

        try {
            $tool('anything', 'facts');
            $this->fail('Expected an HttpException for a 500 response.');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->response?->statusCode);
            $this->assertStringContainsString('POST https://api.getzep.com/api/v2/graph/search', $exception->getMessage());
            $this->assertStringNotContainsString('secret-zep-key', $exception->getMessage());
        }
    }

    protected function existingUser(): Response
    {
        return $this->json(['user_id' => 'user-1']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function json(array $payload): Response
    {
        return new Response(200, [], json_encode($payload));
    }
}
