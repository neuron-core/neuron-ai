<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class GeminiStructuredTest extends TestCase
{
    use RecordsHttpRequests;

    protected const ANSWER = '{"candidates":[{"content":{"role":"model","parts":[{"text":"{\"name\":\"Ada\"}"}]},"finishReason":"STOP"}]}';

    /**
     * @param array<string, mixed> $parameters
     */
    protected function provider(string $model = 'gemini-2.5-flash', array $parameters = [], int $responses = 1): Gemini
    {
        $queue = [];
        for ($i = 0; $i < $responses; $i++) {
            $queue[] = new Response(200, body: self::ANSWER);
        }

        return new Gemini('key', $model, $parameters, httpClient: $this->recordingClient(...$queue));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_schema_is_requested_as_json_with_deterministic_temperature(): void
    {
        $provider = $this->provider();

        $response = $provider->structured(new UserMessage('Who?'), 'Person', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
            'additionalProperties' => false,
        ]);

        $this->assertSame('{"name":"Ada"}', $response->message()->getContent());
        $this->assertSame([
            'temperature' => 0,
            'responseSchema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
            'responseMimeType' => 'application/json',
        ], $this->sentBody()['generationConfig']);
    }

    public function test_user_generation_config_is_kept_and_parameters_are_restored_afterwards(): void
    {
        $provider = $this->provider(parameters: ['generationConfig' => ['temperature' => 0.7, 'topK' => 5]], responses: 2);

        $provider->structured([new UserMessage('Who?')], 'Person', ['type' => 'object', 'properties' => []]);
        $provider->chat(new UserMessage('Plain'));

        $this->assertSame([
            'temperature' => 0.7,
            'topK' => 5,
            'responseSchema' => ['type' => 'object', 'properties' => []],
            'responseMimeType' => 'application/json',
        ], $this->sentBody(0)['generationConfig']);
        $this->assertSame(['temperature' => 0.7, 'topK' => 5], $this->sentBody(1)['generationConfig']);
    }

    public function test_parameters_are_restored_when_the_request_fails(): void
    {
        $provider = new Gemini('key', 'gemini-2.5-flash', httpClient: $this->recordingClient(
            new Response(500, body: '{"error":{"message":"boom"}}'),
            new Response(200, body: self::ANSWER),
        ));

        try {
            $provider->structured(new UserMessage('Who?'), 'Person', ['type' => 'object', 'properties' => []]);
            $this->fail('A 500 response must raise an HttpException.');
        } catch (HttpException) {
        }
        $provider->chat(new UserMessage('Plain'));

        $this->assertArrayNotHasKey('generationConfig', $this->sentBody(1));
    }

    public function test_schema_adaptation_recurses_into_schemas_but_not_into_property_names(): void
    {
        $provider = $this->provider();

        $provider->structured(new UserMessage('Go'), 'Thing', [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                // Property names that collide with schema keywords must survive untouched.
                'type' => ['type' => ['string', 'null']],
                'properties' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [],
                ],
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['x' => ['type' => ['null', 'integer']]]],
                ],
                'choice' => ['anyOf' => [['type' => 'object', 'additionalProperties' => true, 'properties' => []], ['type' => 'null']]],
            ],
        ]);

        $this->assertSame(
            '{"type":"object","properties":{'
            .'"type":{"type":"string"},'
            .'"properties":{"type":"object","properties":{}},'
            .'"items":{"type":"array","items":{"type":"object","properties":{"x":{"type":"integer"}}}},'
            .'"choice":{"anyOf":[{"type":"object","properties":{}},{"type":"null"}]}}}',
            // Decoded as objects so that empty JSON objects stay distinguishable from empty lists.
            json_encode(json_decode((string) $this->sentRequests[0]['request']->getBody(), flags: JSON_THROW_ON_ERROR)->generationConfig->responseSchema, JSON_THROW_ON_ERROR),
        );
    }

    public function test_models_without_schema_support_alongside_tools_get_the_schema_in_the_prompt(): void
    {
        $provider = $this->provider('gemini-2.0-flash');
        $provider->setTools([new ToolStub('lookup')]);
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $provider->structured([new UserMessage('Who?')], 'Person', $schema);

        $body = $this->sentBody();
        $this->assertSame(['temperature' => 0], $body['generationConfig']);
        $this->assertSame(
            [['text' => 'Who? Respond using this JSON schema: '.json_encode($schema)]],
            $body['contents'][0]['parts'],
        );
    }

    public function test_models_with_schema_support_use_the_response_schema_even_with_tools(): void
    {
        $provider = $this->provider('gemini-2.5-pro');
        $provider->setTools([new ToolStub('lookup')]);

        $provider->structured([new UserMessage('Who?')], 'Person', ['type' => 'object', 'properties' => []]);

        $body = $this->sentBody();
        $this->assertSame('application/json', $body['generationConfig']['responseMimeType']);
        $this->assertSame([['text' => 'Who?']], $body['contents'][0]['parts']);
    }
}
