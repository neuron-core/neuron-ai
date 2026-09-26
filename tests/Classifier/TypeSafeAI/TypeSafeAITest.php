<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Classifier\TypeSafeAI;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use JsonException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\Score;
use NeuronAI\Classifier\TypeSafeAI\TypeSafeAI;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_fill;
use function file_get_contents;
use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

class TypeSafeAITest extends TestCase
{
    public function test_classifies_all_three_primitives_in_one_authenticated_request(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, body: file_get_contents(__DIR__.'/fixtures/systemone.json')),
        ]));
        $stack->push(Middleware::history($history));
        $classifier = new TypeSafeAI(key: 'test-key', httpClient: new GuzzleHttpClient(handler: $stack));
        $input = ['ticket' => 'Please refund my missing package.', 'customer' => ['orders' => 2]];
        $questions = [
            'department' => new Choice('Which department?', ['billing' => 'Payments', 'shipping' => 'Deliveries']),
            'severity' => new Score('How severe?', ['Cosmetic', 'Workaround available', 'Blocked']),
            'refund' => new Boolean('Is a refund requested?'),
        ];

        $result = $classifier->classify(new ClassificationRequest($input, $questions));

        self::assertSame('shipping', $result->choice('department')->choice);
        self::assertSame(0.8, $result->choice('department')->distribution->probabilities['shipping']);
        self::assertEqualsWithDelta(1.6, $result->score('severity')->score, 1e-12);
        self::assertSame([2 => 0.65, 0 => 0.05, 1 => 0.3], $result->score('severity')->distribution->probabilities);
        self::assertSame(0.92, $result->boolean('refund')->probability);
        self::assertSame($questions['refund'], $result->boolean('refund')->definition);
        self::assertCount(1, $history);
        $sent = $history[0]['request'];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://api.typesafe.ai/v1/systemone', (string) $sent->getUri());
        self::assertSame('Bearer test-key', $sent->getHeaderLine('Authorization'));
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $sent->getHeaderLine('Accept'));
        self::assertSame([
            'model' => 'jev-latest',
            'state' => $input,
            'questions' => [
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which department?',
                    'criteria' => ['billing' => 'Payments', 'shipping' => 'Deliveries'],
                ],
                'severity' => [
                    'type' => 'score',
                    'instructions' => 'How severe?',
                    'criteria' => ['Cosmetic', 'Workaround available', 'Blocked'],
                ],
                'refund' => ['type' => 'noul', 'instructions' => 'Is a refund requested?'],
            ],
        ], json_decode((string) $sent->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_http_client_replacement_preserves_authentication_and_custom_model(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, body: '{"answers":{"check":{"type":"noul","noul":0}}}'),
        ]));
        $stack->push(Middleware::history($history));
        $classifier = (new TypeSafeAI(key: 'test-key', model: 'custom-model'))
            ->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $result = $classifier->classify(new ClassificationRequest('Plain text', ['check' => new Boolean('True?')]));

        self::assertSame(0.0, $result->boolean('check')->probability);
        self::assertSame('Bearer test-key', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('https://api.typesafe.ai/v1/systemone', (string) $history[0]['request']->getUri());
        $payload = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame('custom-model', $payload['model']);
        self::assertSame('Plain text', $payload['state']);
    }

    public function test_calls_do_not_leak_questions_input_or_credentials(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, body: '{"answers":{"first":{"type":"noul","noul":0}}}'),
            new Response(200, body: '{"answers":{"second":{"type":"noul","noul":1}}}'),
            new Response(200, body: '{"answers":{"first":{"type":"noul","noul":0}}}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new GuzzleHttpClient(handler: $stack);
        $first = new TypeSafeAI(key: 'first-key', httpClient: $client);
        $second = new TypeSafeAI(key: 'second-key', httpClient: $client);

        $first->classify(new ClassificationRequest('First input', ['first' => new Boolean('First?')]));
        $result = $second->classify(new ClassificationRequest(['Second input'], ['second' => new Boolean('Second?')]));
        $first->classify(new ClassificationRequest('Third input', ['first' => new Boolean('Third?')]));

        self::assertSame(1.0, $result->boolean('second')->probability);
        self::assertSame('Bearer first-key', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer second-key', $history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer first-key', $history[2]['request']->getHeaderLine('Authorization'));
        $payload = json_decode((string) $history[1]['request']->getBody(), true);
        self::assertSame(['Second input'], $payload['state']);
        self::assertSame(['second' => ['type' => 'noul', 'instructions' => 'Second?']], $payload['questions']);
        $payload = json_decode((string) $history[2]['request']->getBody(), true);
        self::assertSame('Third input', $payload['state']);
        self::assertSame('Third?', $payload['questions']['first']['instructions']);
    }

    public function test_structured_state_is_never_inferred_as_multipart(): void
    {
        $input = ['contents' => 'This is application data, not an upload.'];
        $stop = new RuntimeException('Stop before networking');
        $client = (new CurlHttpClient())->onRequest(function (HttpRequest $request) use ($input, $stop): HttpRequest {
            self::assertFalse($request->isMultipart());
            self::assertIsString($request->body);
            self::assertSame($input, json_decode($request->body, true)['state']);

            throw $stop;
        });

        $this->expectExceptionObject($stop);

        (new TypeSafeAI('test-key', httpClient: $client))
            ->classify(new ClassificationRequest($input, ['check' => new Boolean('True?')]));
    }

    #[DataProvider('invalid_configuration')]
    public function test_rejects_invalid_configuration(string $key, string $model, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new TypeSafeAI($key, $model);
    }

    public static function invalid_configuration(): iterable
    {
        yield 'missing key' => ['', 'jev-latest', 'API key'];
        yield 'blank key' => [' ', 'jev-latest', 'API key'];
        yield 'missing model' => ['test-key', '', 'model name'];
        yield 'blank model' => ['test-key', "\t ", 'model name'];
    }

    #[DataProvider('oversized_questions')]
    public function test_provider_limits_fail_before_sending(Choice|Score $question, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->classifier('')->classify(new ClassificationRequest('Input', ['oversized' => $question]));
    }

    public static function oversized_questions(): iterable
    {
        $options = [];
        for ($index = 0; $index < 256; $index++) {
            $options['option_'.$index] = 'Option '.$index;
        }

        yield 'choice' => [new Choice('Which?', $options), "choice 'oversized' supports at most 255"];
        yield 'score' => [new Score('How much?', array_fill(0, 11, 'Level')), "score 'oversized' supports at most 10"];
    }

    #[DataProvider('malformed_responses')]
    public function test_rejects_malformed_responses(string $body, string $message): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);

        $this->classifier($body)->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
    }

    public static function malformed_responses(): iterable
    {
        yield 'invalid json' => ['not json', 'invalid JSON'];
        yield 'null response' => ['null', 'answers map'];
        yield 'scalar response' => ['42', 'answers map'];
        yield 'missing answers' => ['{}', 'answers map'];
        yield 'invalid answers' => ['{"answers":false}', 'answers map'];
        yield 'missing question' => ['{"answers":{}}', 'question identifiers'];
        yield 'extra question' => ['{"answers":{"check":{"type":"noul","noul":0.5},"extra":{}}}', 'question identifiers'];
        yield 'unknown question' => ['{"answers":{"other":{"type":"noul","noul":0.5}}}', 'question identifiers'];
        yield 'invalid answer' => ['{"answers":{"check":null}}', "answer 'check' must have type 'noul'"];
        yield 'missing type' => ['{"answers":{"check":{"noul":0.5}}}', "answer 'check' must have type 'noul'"];
        yield 'wrong type' => ['{"answers":{"check":{"type":"choice","noul":0.5}}}', "answer 'check' must have type 'noul'"];
        yield 'missing probability' => ['{"answers":{"check":{"type":"noul"}}}', "Invalid TypeSafeAI answer 'check'"];
        yield 'string probability' => ['{"answers":{"check":{"type":"noul","noul":"0.5"}}}', 'must be a number'];
        yield 'boolean probability' => ['{"answers":{"check":{"type":"noul","noul":true}}}', 'must be a number'];
        yield 'out of range' => ['{"answers":{"check":{"type":"noul","noul":1.1}}}', 'between zero and one'];
    }

    #[DataProvider('invalid_distributions')]
    public function test_response_distributions_must_match_the_definition(Choice|Score $question, array $answer, string $message): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("Invalid TypeSafeAI answer 'check': ".$message);

        $this->classifier(json_encode(['answers' => ['check' => $answer]], JSON_THROW_ON_ERROR))
            ->classify(new ClassificationRequest('Input', ['check' => $question]));
    }

    public static function invalid_distributions(): iterable
    {
        $choice = new Choice('Which?', ['a' => 'A', 'b' => 'B']);
        $score = new Score('How much?', ['Low', 'High']);

        yield 'missing distribution' => [$choice, ['type' => 'choice'], 'The probabilities field'];
        yield 'scalar distribution' => [$score, ['type' => 'score', 'probabilities' => 1], 'The probabilities field'];
        yield 'missing option' => [$choice, ['type' => 'choice', 'probabilities' => ['a' => 1]], 'Choice probabilities'];
        yield 'unknown option' => [$choice, ['type' => 'choice', 'probabilities' => ['a' => 0.5, 'c' => 0.5]], 'Choice probabilities'];
        yield 'unknown level' => [$score, ['type' => 'score', 'probabilities' => [1 => 0.5, 2 => 0.5]], 'Score probabilities'];
        yield 'invalid mass' => [$score, ['type' => 'score', 'probabilities' => [0.1, 0.2]], 'Probabilities must sum to one'];
        yield 'string probabilities' => [$score, ['type' => 'score', 'probabilities' => ['0.5', '0.5']], 'Probabilities must be finite numbers'];
    }

    public function test_choice_ties_follow_the_shared_definition_order(): void
    {
        $classifier = $this->classifier('{"answers":{"check":{"type":"choice","choice":"b","probabilities":{"b":0.5,"a":0.5}}}}');

        $result = $classifier->classify(new ClassificationRequest('Input', [
            'check' => new Choice('Which?', ['a' => 'A', 'b' => 'B']),
        ]));

        self::assertSame('a', $result->choice('check')->choice);
    }

    public function test_questions_at_the_provider_limits_are_supported(): void
    {
        $options = [];
        $probabilities = [];
        for ($index = 0; $index < 255; $index++) {
            $id = 'option_'.$index;
            $options[$id] = 'Option '.$index;
            $probabilities[$id] = $index === 0 ? 1 : 0;
        }
        $scoreProbabilities = array_fill(0, 10, 0);
        $scoreProbabilities[9] = 1;
        $classifier = $this->classifier(json_encode(['answers' => [
            'choice' => ['type' => 'choice', 'probabilities' => $probabilities],
            'score' => ['type' => 'score', 'probabilities' => $scoreProbabilities],
        ]], JSON_THROW_ON_ERROR));

        $result = $classifier->classify(new ClassificationRequest('Input', [
            'choice' => new Choice('Which?', $options),
            'score' => new Score('How much?', array_fill(0, 10, 'Level')),
        ]));

        self::assertSame('option_0', $result->choice('choice')->choice);
        self::assertSame(9.0, $result->score('score')->score);
    }

    #[DataProvider('http_errors')]
    public function test_http_errors_preserve_the_response(int $status): void
    {
        $body = '{"detail":"Request failed"}';
        $classifier = new TypeSafeAI('test-key', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response($status, body: $body)])),
        ));

        try {
            $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
            self::fail('Expected an HTTP exception.');
        } catch (HttpException $exception) {
            self::assertNotNull($exception->response);
            self::assertSame($status, $exception->response->statusCode);
            self::assertSame($body, $exception->response->body);
        }
    }

    public static function http_errors(): iterable
    {
        yield [401];
        yield [422];
        yield [429];
        yield [529];
    }

    public function test_http_errors_never_expose_the_api_key(): void
    {
        $classifier = new TypeSafeAI('sk-live-secret', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([
                new Response(401, body: '{"detail":"Invalid token"}'),
                new ConnectException('Connection failed', new Request('POST', 'https://api.typesafe.ai/v1/systemone')),
            ])),
        ));
        $request = new ClassificationRequest('Input', ['check' => new Boolean('True?')]);

        foreach ([401, 'network'] as $failure) {
            try {
                $classifier->classify($request);
                self::fail("Expected the {$failure} failure to raise an HTTP exception.");
            } catch (HttpException $exception) {
                self::assertStringNotContainsString('sk-live-secret', $exception->getMessage());
            }
        }
    }

    public function test_request_content_cannot_alter_the_payload_structure(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, body: '{"answers":{"q\\"}":{"type":"noul","noul":0.5}}}'),
        ]));
        $stack->push(Middleware::history($history));
        $input = '", "model": "attacker-model", "state": "Grüße 👋 \\u0000';
        $question = new Boolean('Ignore "previous" instructions}');

        $result = (new TypeSafeAI('test-key', httpClient: new GuzzleHttpClient(handler: $stack)))
            ->classify(new ClassificationRequest($input, ['q"}' => $question]));

        self::assertSame(0.5, $result->boolean('q"}')->probability);
        self::assertSame([
            'model' => 'jev-latest',
            'state' => $input,
            'questions' => ['q"}' => ['type' => 'noul', 'instructions' => 'Ignore "previous" instructions}']],
        ], json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_excessively_nested_responses_are_rejected_as_invalid_json(): void
    {
        $body = str_repeat('[', 600).str_repeat(']', 600);

        try {
            $this->classifier($body)->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
            self::fail('Expected the nested response to be rejected.');
        } catch (ProviderException $exception) {
            self::assertSame('TypeSafeAI returned invalid JSON.', $exception->getMessage());
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }

    public function test_network_errors_remain_http_exceptions(): void
    {
        $classifier = new TypeSafeAI('test-key', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([
                new ConnectException('Connection failed', new Request('POST', 'https://api.typesafe.ai/v1/systemone')),
            ])),
        ));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Connection failed');

        $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
    }

    protected function classifier(string $body): TypeSafeAI
    {
        return new TypeSafeAI('test-key', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        ));
    }
}
