<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;
use stdClass;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The structured-output schema name is built from a PHP class name. The class
 * name of an anonymous subclass embeds a NUL byte, '@', '/', ':' and '$', none
 * of which OpenAI accepts in a schema name ([a-zA-Z0-9_-] only).
 */
class StructuredSchemaNameInjectionSecurityTest extends TestCase
{
    use RecordsHttpRequests;

    protected const ALLOWED_NAME = '/^[a-zA-Z][a-zA-Z0-9_-]*$/D';

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function anonymousSubclass(): string
    {
        return (new class () extends stdClass {})::class;
    }

    public function test_chat_completions_schema_name_of_an_anonymous_subclass_uses_only_allowed_characters(): void
    {
        $answer = '{"choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"{}"}}]}';
        $provider = new OpenAI('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $answer)));

        $provider->structured(new UserMessage('Who?'), $this->anonymousSubclass(), ['type' => 'object']);

        $this->assertMatchesRegularExpression(self::ALLOWED_NAME, $this->sentBody()['response_format']['json_schema']['name']);
    }

    public function test_responses_schema_name_of_an_anonymous_subclass_uses_only_allowed_characters(): void
    {
        $answer = '{"status":"completed","output":[{"type":"message","content":[{"type":"output_text","text":"{}"}]}]}';
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $answer)));

        $provider->structured([new UserMessage('Who?')], $this->anonymousSubclass(), ['type' => 'object']);

        $this->assertMatchesRegularExpression(self::ALLOWED_NAME, $this->sentBody()['text']['format']['name']);
    }
}
