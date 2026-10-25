<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Classifier\Cloudflare;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use JsonException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\Cloudflare\WorkersAI;
use NeuronAI\Classifier\Score;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Tests\Classifier\Stub\SupportTicket;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_fill;
use function array_keys;
use function array_map;
use function file_get_contents;
use function json_decode;
use function json_encode;
use function range;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

class WorkersAITest extends TestCase
{
    use RecordsHttpRequests;

    protected const ACCOUNT_ID = '023e105f4ecef8ad9ca31a8372d0c353';

    protected const ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/023e105f4ecef8ad9ca31a8372d0c353/ai/run/@cf/cloudflare/clef';

    public function test_classifies_through_the_account_run_endpoint(): void
    {
        $classifier = new WorkersAI(self::ACCOUNT_ID, 'test-token', httpClient: $this->recordingClient(
            new Response(200, body: file_get_contents(__DIR__.'/fixtures/run.json')),
        ));
        $request = SupportTicket::request();

        $result = $classifier->classify($request);

        self::assertSame('shipping', $result->choice('department')->choice);
        self::assertSame(0.9, $result->choice('department')->distribution->probabilities['shipping']);
        self::assertEqualsWithDelta(1.2, $result->score('severity')->score, 1e-12);
        self::assertSame([0 => 0.1, 1 => 0.6, 2 => 0.3], $result->score('severity')->distribution->probabilities);
        self::assertSame(0.97, $result->boolean('refund')->probability);
        self::assertSame($request->questions['refund'], $result->boolean('refund')->definition);
        self::assertCount(1, $this->sentRequests);
        $sent = $this->sentRequests[0]['request'];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame(self::ENDPOINT, (string) $sent->getUri());
        self::assertSame('Bearer test-token', $sent->getHeaderLine('Authorization'));
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $sent->getHeaderLine('Accept'));
        self::assertSame([
            'model' => 'clef',
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

    public function test_flash_model_selects_its_path_and_body_model(): void
    {
        $classifier = new WorkersAI(self::ACCOUNT_ID, 'test-token', 'clef-flash', $this->recordingClient(
            new Response(200, body: self::envelope('{"model":"clef-flash","answers":{"check":{"type":"noul","noul":0.25}}}')),
        ));

        $result = $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));

        self::assertSame(0.25, $result->boolean('check')->probability);
        self::assertSame(
            'https://api.cloudflare.com/client/v4/accounts/'.self::ACCOUNT_ID.'/ai/run/@cf/cloudflare/clef-flash',
            (string) $this->sentRequests[0]['request']->getUri(),
        );
        self::assertSame('clef-flash', json_decode((string) $this->sentRequests[0]['request']->getBody(), true)['model']);
    }

    public function test_http_client_replacement_preserves_authentication(): void
    {
        $classifier = (new WorkersAI(self::ACCOUNT_ID, 'test-token'))->setHttpClient($this->recordingClient(
            new Response(200, body: self::envelope('{"model":"clef","answers":{"check":{"type":"noul","noul":0}}}')),
        ));

        $result = $classifier->classify(new ClassificationRequest('Plain text', ['check' => new Boolean('True?')]));

        self::assertSame(0.0, $result->boolean('check')->probability);
        self::assertSame('Bearer test-token', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        self::assertSame(self::ENDPOINT, (string) $this->sentRequests[0]['request']->getUri());
    }

    public function test_account_id_is_url_encoded_in_the_path(): void
    {
        $classifier = new WorkersAI('team/../a b?x#y', 'test-token', httpClient: $this->recordingClient(
            new Response(200, body: self::envelope('{"model":"clef","answers":{"check":{"type":"noul","noul":0.5}}}')),
        ));

        $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));

        self::assertSame(
            'https://api.cloudflare.com/client/v4/accounts/team%2F..%2Fa%20b%3Fx%23y/ai/run/@cf/cloudflare/clef',
            (string) $this->sentRequests[0]['request']->getUri(),
        );
    }

    #[DataProvider('bodies_without_a_result_object')]
    public function test_missing_result_envelope_is_a_provider_exception(string $body): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Workers AI response must contain a result object.');

        $this->classifier($body)->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
    }

    public static function bodies_without_a_result_object(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'failed call' => ['{"result":null,"success":false,"errors":[{"code":1,"message":"x"}]}'];
        yield 'string result' => ['{"result":"x"}'];
        yield 'bare System One body' => ['{"model":"clef","answers":{"check":{"type":"noul","noul":0.5}}}'];
    }

    public function test_invalid_json_names_workers_ai(): void
    {
        try {
            $this->classifier('not json')->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
            self::fail('Expected the response to be rejected.');
        } catch (ProviderException $exception) {
            self::assertSame('Workers AI returned invalid JSON.', $exception->getMessage());
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());
        }
    }

    #[DataProvider('malformed_results')]
    public function test_malformed_answers_inside_the_envelope_name_workers_ai(
        Choice|Score|Boolean $question,
        string $result,
        string $message,
    ): void {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);

        $this->classifier(self::envelope($result))->classify(new ClassificationRequest('Input', ['check' => $question]));
    }

    public static function malformed_results(): iterable
    {
        $boolean = new Boolean('True?');
        $choice = new Choice('Which?', ['a' => 'A', 'b' => 'B']);
        $score = new Score('How much?', ['Low', 'High']);

        yield 'missing answers' => [$boolean, '{"model":"clef"}', 'Workers AI response must contain an answers map.'];
        yield 'invalid answers' => [$boolean, '{"answers":false}', 'Workers AI response must contain an answers map.'];
        yield 'missing question' => [
            $boolean,
            '{"answers":{}}',
            'Workers AI answers must match the requested question identifiers.',
        ];
        yield 'unknown question' => [
            $boolean,
            '{"answers":{"other":{"type":"noul","noul":0.5}}}',
            'Workers AI answers must match the requested question identifiers.',
        ];
        yield 'wrong type' => [
            $boolean,
            '{"answers":{"check":{"type":"choice","noul":0.5}}}',
            "Workers AI answer 'check' must have type 'noul'.",
        ];
        yield 'string probability' => [
            $boolean,
            '{"answers":{"check":{"type":"noul","noul":"0.5"}}}',
            "Invalid Workers AI answer 'check': The noul probability must be a number.",
        ];
        yield 'out of range' => [
            $boolean,
            '{"answers":{"check":{"type":"noul","noul":1.1}}}',
            "Invalid Workers AI answer 'check': Boolean probability must be finite and between zero and one.",
        ];
        yield 'missing distribution' => [
            $choice,
            '{"answers":{"check":{"type":"choice","choice":"a","confidence":1}}}',
            "Invalid Workers AI answer 'check': The probabilities field must contain a distribution.",
        ];
        yield 'unknown option' => [
            $choice,
            '{"answers":{"check":{"type":"choice","choice":"a","probabilities":{"a":0.5,"c":0.5}}}}',
            "Invalid Workers AI answer 'check': Choice probabilities must cover exactly the defined options.",
        ];
        yield 'invalid mass' => [
            $score,
            '{"answers":{"check":{"type":"score","score":0.7,"probabilities":{"0":0.1,"1":0.2}}}}',
            "Invalid Workers AI answer 'check': Probabilities must sum to one.",
        ];
    }

    #[DataProvider('requests_beyond_the_limits')]
    public function test_question_limits_fail_before_sending(ClassificationRequest $request, string $message): void
    {
        $classifier = new WorkersAI(self::ACCOUNT_ID, 'test-token', httpClient: $this->recordingClient(new Response(200, body: '{}')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        try {
            $classifier->classify($request);
        } finally {
            self::assertSame([], $this->sentRequests);
        }
    }

    public static function requests_beyond_the_limits(): iterable
    {
        $invalidIdentifier = static fn (string $id): string
            => "Workers AI question identifier '{$id}' must be 1 to 100 letters, digits, '_', '.' or '-'.";
        $options = [];
        for ($index = 0; $index < 256; $index++) {
            $options['option_'.$index] = 'Option '.$index;
        }

        yield '65 questions' => [
            self::booleans(array_map(static fn (int $index): string => 'question_'.$index, range(1, 65))),
            'Workers AI accepts at most 64 questions per request.',
        ];
        yield 'space' => [self::booleans(['has space']), $invalidIdentifier('has space')];
        yield 'slash' => [self::booleans(['a/b']), $invalidIdentifier('a/b')];
        yield 'trailing newline' => [self::booleans(["abc\n"]), $invalidIdentifier("abc\n")];
        yield '101 characters' => [self::booleans([str_repeat('a', 101)]), $invalidIdentifier(str_repeat('a', 101))];
        // Cloudflare documents "letters" without naming a charset; this row pins the ASCII reading.
        yield 'non-ASCII letter' => [self::booleans(['é']), $invalidIdentifier('é')];
        yield '256 options' => [
            new ClassificationRequest('Input', ['oversized' => new Choice('Which?', $options)]),
            "Workers AI choice 'oversized' supports at most 255 options.",
        ];
        yield '11 levels' => [
            new ClassificationRequest('Input', ['oversized' => new Score('How much?', array_fill(0, 11, 'Level'))]),
            "Workers AI score 'oversized' supports at most 10 levels.",
        ];
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('identifiers_at_the_limits')]
    public function test_questions_at_the_limits_are_supported(array $ids): void
    {
        $answers = [];
        foreach ($ids as $id) {
            $answers[$id] = ['type' => 'noul', 'noul' => 0.5];
        }
        $classifier = new WorkersAI(self::ACCOUNT_ID, 'test-token', httpClient: $this->recordingClient(
            new Response(200, body: self::envelope(json_encode(['model' => 'clef', 'answers' => $answers], JSON_THROW_ON_ERROR))),
        ));

        $result = $classifier->classify(self::booleans($ids));

        self::assertCount(1, $this->sentRequests);
        self::assertSame($ids, array_keys(json_decode((string) $this->sentRequests[0]['request']->getBody(), true)['questions']));
        self::assertSame($ids, array_keys($result->answers));
    }

    public static function identifiers_at_the_limits(): iterable
    {
        yield '64 questions' => [array_map(static fn (int $index): string => 'question_'.$index, range(1, 64))];
        yield 'every allowed character class' => [['a.b-c_D9']];
        yield '100 characters' => [[str_repeat('a', 100)]];
    }

    #[DataProvider('invalid_configuration')]
    public function test_rejects_invalid_configuration(string $accountId, string $key, string $model, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new WorkersAI($accountId, $key, $model);
    }

    public static function invalid_configuration(): iterable
    {
        yield 'missing account id' => ['', 'test-token', 'clef', 'Workers AI requires a non-empty account ID.'];
        yield 'blank account id' => [" \t", 'test-token', 'clef', 'Workers AI requires a non-empty account ID.'];
        yield 'missing key' => [self::ACCOUNT_ID, '', 'clef', 'Workers AI requires a non-empty API key.'];
        yield 'blank key' => [self::ACCOUNT_ID, ' ', 'clef', 'Workers AI requires a non-empty API key.'];
        yield 'missing model' => [self::ACCOUNT_ID, 'test-token', '', 'Workers AI requires a non-empty model name.'];
        yield 'blank model' => [self::ACCOUNT_ID, 'test-token', "\t ", 'Workers AI requires a non-empty model name.'];
    }

    #[DataProvider('http_errors')]
    public function test_http_errors_preserve_the_response_and_never_expose_the_token(int $status): void
    {
        $body = '{"result":null,"success":false,"errors":[{"code":10000,"message":"Request failed"}],"messages":[]}';
        $classifier = new WorkersAI(self::ACCOUNT_ID, 'cf-secret-token', httpClient: $this->recordingClient(new Response($status, body: $body)));

        try {
            $classifier->classify(new ClassificationRequest('Input', ['check' => new Boolean('True?')]));
            self::fail('Expected an HTTP exception.');
        } catch (HttpException $exception) {
            self::assertNotNull($exception->response);
            self::assertSame($status, $exception->response->statusCode);
            self::assertSame($body, $exception->response->body);
            self::assertSame(self::ENDPOINT, $exception->request?->uri);

            for ($failure = $exception; $failure instanceof Throwable; $failure = $failure->getPrevious()) {
                self::assertStringNotContainsString('cf-secret-token', $failure->getMessage());
            }
        }
    }

    public static function http_errors(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'rate limited' => [429];
        yield 'server error' => [500];
    }

    protected static function envelope(string $result): string
    {
        return '{"result":'.$result.',"success":true,"errors":[],"messages":[]}';
    }

    /**
     * @param list<string> $ids
     */
    protected static function booleans(array $ids): ClassificationRequest
    {
        $questions = [];
        foreach ($ids as $id) {
            $questions[$id] = new Boolean('True?');
        }

        return new ClassificationRequest('Input', $questions);
    }

    protected function classifier(string $body): WorkersAI
    {
        return new WorkersAI(self::ACCOUNT_ID, 'test-token', httpClient: $this->recordingClient(new Response(200, body: $body)));
    }
}
