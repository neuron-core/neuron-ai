<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Classifier\SystemOne;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\Score;
use NeuronAI\Classifier\SystemOne\SystemOne;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tests\Classifier\Stub\SupportTicket;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class SystemOneTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_self_hosted_server_receives_a_system_one_request_without_authentication(): void
    {
        $classifier = $this->selfHosted(new Response(200, body: <<<'JSON'
            {
                "model": "Cloudflare/clef",
                "answers": {
                    "refund": {"type": "noul", "noul": 0.97},
                    "department": {
                        "type": "choice",
                        "choice": "shipping",
                        "confidence": 0.85,
                        "probabilities": {"billing": 0.05, "shipping": 0.9, "other": 0.05}
                    },
                    "severity": {
                        "type": "score",
                        "score": 1.2,
                        "confidence": 0.4,
                        "legend": {"0": "Cosmetic issue.", "1": "Workaround available.", "2": "Completely blocked."},
                        "probabilities": {"0": 0.1, "1": 0.6, "2": 0.3}
                    }
                },
                "usage": {"input_tokens": 180, "output_tokens": 14}
            }
            JSON));
        $request = SupportTicket::request();

        $result = $classifier->classify($request);

        self::assertSame('shipping', $result->choice('department')->choice);
        self::assertSame(
            ['billing' => 0.05, 'shipping' => 0.9, 'other' => 0.05],
            $result->choice('department')->distribution->probabilities,
        );
        self::assertEqualsWithDelta(1.2, $result->score('severity')->score, 1e-12);
        self::assertSame([0 => 0.1, 1 => 0.6, 2 => 0.3], $result->score('severity')->distribution->probabilities);
        self::assertSame(0.97, $result->boolean('refund')->probability);
        self::assertSame($request->questions['department'], $result->choice('department')->definition);
        self::assertSame($request->questions['severity'], $result->score('severity')->definition);
        self::assertSame($request->questions['refund'], $result->boolean('refund')->definition);
        self::assertCount(1, $this->sentRequests);
        $sent = $this->sentRequests[0]['request'];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('http://localhost:30000/v1/systemone', (string) $sent->getUri());
        self::assertFalse($sent->hasHeader('Authorization'));
        self::assertSame('application/json', $sent->getHeaderLine('Accept'));
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame([
            'model' => 'Cloudflare/clef',
            'state' => ['ticket' => 'My parcel never arrived, refund me.'],
            'questions' => [
                'department' => [
                    'type' => 'choice',
                    'instructions' => 'Which department should handle this ticket?',
                    'criteria' => [
                        'billing' => 'Charges and payment problems.',
                        'shipping' => 'Delivery and tracking problems.',
                        'other' => 'Requests outside these departments.',
                    ],
                ],
                'severity' => [
                    'type' => 'score',
                    'instructions' => 'How severe is the reported problem?',
                    'criteria' => ['Cosmetic issue.', 'Workaround available.', 'Completely blocked.'],
                ],
                'refund' => ['type' => 'noul', 'instructions' => 'Does the customer request a refund?'],
            ],
        ], json_decode((string) $sent->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_a_key_is_sent_as_a_bearer_token(): void
    {
        $classifier = new SystemOne(
            baseUri: 'https://clef.internal.test/v1',
            model: 'Cloudflare/clef',
            key: 'local-token',
            httpClient: $this->recordingClient(new Response(200, body: '{"answers":{"check":{"type":"noul","noul":0.5}}}')),
        );

        $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));

        $sent = $this->sentRequests[0]['request'];
        self::assertSame('Bearer local-token', $sent->getHeaderLine('Authorization'));
        self::assertSame('https://clef.internal.test/v1/systemone', (string) $sent->getUri());
    }

    public function test_endpoint_ignores_a_trailing_slash(): void
    {
        $classifier = new SystemOne(
            baseUri: 'http://localhost:30000/v1/',
            model: 'Cloudflare/clef',
            httpClient: $this->recordingClient(new Response(200, body: '{"answers":{"check":{"type":"noul","noul":0.5}}}')),
        );

        $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));

        self::assertSame('http://localhost:30000/v1/systemone', (string) $this->sentRequests[0]['request']->getUri());
    }

    #[DataProvider('malformed_responses')]
    public function test_errors_name_the_protocol(string $body, string $message): void
    {
        $classifier = $this->selfHosted(new Response(200, body: $body));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);

        $classifier->classify(new ClassificationRequest('Input', ['severity' => new Score('How severe?', ['Low', 'High'])]));
    }

    public static function malformed_responses(): iterable
    {
        yield 'invalid json' => ['not json', 'System One returned invalid JSON.'];
        yield 'missing answers map' => ['{"model":"Cloudflare/clef"}', 'System One response must contain an answers map.'];
        yield 'mismatched identifiers' => [
            '{"answers":{"urgency":{"type":"score","probabilities":[0.5,0.5]}}}',
            'System One answers must match the requested question identifiers.',
        ];
        yield 'wrong answer type' => [
            '{"answers":{"severity":{"type":"noul","noul":0.5}}}',
            "System One answer 'severity' must have type 'score'.",
        ];
        yield 'invalid distribution' => [
            '{"answers":{"severity":{"type":"score","probabilities":[0.2,0.2]}}}',
            "Invalid System One answer 'severity': Probabilities must sum to one.",
        ];
    }

    #[DataProvider('oversized_questions')]
    public function test_protocol_limits_fail_before_sending(Choice|Score $question, string $message): void
    {
        // An empty mock queue throws before the history records anything, so a response is queued to make a send visible.
        $classifier = $this->selfHosted(new Response(200, body: '{"answers":{}}'));

        try {
            $classifier->classify(new ClassificationRequest('Input', ['oversized' => $question]));
            self::fail('Expected the protocol limit to reject the question.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $this->sentRequests);
    }

    public static function oversized_questions(): iterable
    {
        $options = [];
        for ($index = 0; $index < 256; $index++) {
            $options['option_'.$index] = 'Option '.$index;
        }

        yield '256 options' => [new Choice('Which?', $options), "System One choice 'oversized' supports at most 255 options."];
        yield '11 levels' => [
            new Score('How much?', array_fill(0, 11, 'Level')),
            "System One score 'oversized' supports at most 10 levels.",
        ];
    }

    public function test_null_option_descriptions_are_sent_as_null_criteria(): void
    {
        $classifier = $this->selfHosted(new Response(
            200,
            body: '{"answers":{"department":{"type":"choice","probabilities":{"billing":0.3,"other":0.7}}}}',
        ));

        $result = $classifier->classify(new ClassificationRequest('Input', [
            'department' => new Choice('Which department?', ['billing' => 'Charges and payment problems.', 'other' => null]),
        ]));

        self::assertSame('other', $result->choice('department')->choice);
        $payload = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['billing' => 'Charges and payment problems.', 'other' => null],
            $payload['questions']['department']['criteria'],
        );
    }

    #[DataProvider('invalid_configuration')]
    public function test_rejects_invalid_configuration(?string $key, string $model, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new SystemOne(baseUri: 'http://localhost:30000/v1', model: $model, key: $key);
    }

    public static function invalid_configuration(): iterable
    {
        yield 'missing model' => [null, '', 'System One requires a non-empty model name.'];
        yield 'blank model' => [null, "\t ", 'System One requires a non-empty model name.'];
        yield 'missing key' => ['', 'Cloudflare/clef', 'System One requires a non-empty API key.'];
        yield 'blank key' => [' ', 'Cloudflare/clef', 'System One requires a non-empty API key.'];
    }

    #[DataProvider('http_errors')]
    public function test_http_errors_preserve_the_response(int $status): void
    {
        $body = '{"object":"error","message":"Request failed"}';
        $classifier = $this->selfHosted(new Response($status, body: $body));

        try {
            $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
            self::fail('Expected an HTTP exception.');
        } catch (HttpException $exception) {
            self::assertSame('http://localhost:30000/v1/systemone', $exception->request?->uri);
            self::assertNotNull($exception->response);
            self::assertSame($status, $exception->response->statusCode);
            self::assertSame($body, $exception->response->body);
        }
    }

    public static function http_errors(): iterable
    {
        yield [400];
        yield [500];
        yield [503];
    }

    protected function selfHosted(Response ...$responses): SystemOne
    {
        return new SystemOne(
            baseUri: 'http://localhost:30000/v1',
            model: 'Cloudflare/clef',
            httpClient: $this->recordingClient(...$responses),
        );
    }
}
