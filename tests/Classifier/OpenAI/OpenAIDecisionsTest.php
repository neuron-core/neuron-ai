<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Classifier\OpenAI;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use JsonException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\OpenAI\OpenAIDecisions;
use NeuronAI\Classifier\Refusal;
use NeuronAI\Classifier\Score;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Tests\Classifier\Stub\SupportTicket;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use function array_diff_key;
use function array_fill;
use function array_keys;
use function array_map;
use function file_get_contents;
use function json_decode;
use function json_encode;
use function range;
use function str_repeat;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

class OpenAIDecisionsTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_classifies_all_three_primitives_in_one_authenticated_request(): void
    {
        $request = SupportTicket::request();
        $classifier = new OpenAIDecisions(key: 'test-key', httpClient: $this->client(self::decision()));

        $result = $classifier->classify($request);

        self::assertSame('shipping', $result->choice('department')->choice);
        self::assertSame(
            ['billing' => 0.05, 'shipping' => 0.9, 'other' => 0.05],
            $result->choice('department')->distribution->probabilities,
        );
        self::assertEqualsWithDelta(1.2, $result->score('severity')->score, 1e-12);
        self::assertSame([0 => 0.1, 1 => 0.6, 2 => 0.3], $result->score('severity')->distribution->probabilities);
        self::assertSame(0.97, $result->boolean('refund')->probability);
        self::assertSame($request->questions['refund'], $result->boolean('refund')->definition);
        self::assertCount(1, $this->sentRequests);
        $sent = $this->sentRequests[0]['request'];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('https://api.openai.com/v1/decisions', (string) $sent->getUri());
        self::assertSame('Bearer test-key', $sent->getHeaderLine('Authorization'));
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $sent->getHeaderLine('Accept'));
        self::assertSame([
            'model' => 'gpt-6-luna',
            'input' => [[
                'role' => 'user',
                'content' => [['type' => 'input_json', 'data' => ['ticket' => 'My parcel never arrived, refund me.']]],
            ]],
            'questions' => [
                [
                    'type' => 'choice',
                    'name' => 'department',
                    'instructions' => 'Which department should handle this ticket?',
                    'choices' => [
                        ['value' => 'billing', 'description' => 'Charges and payment problems.'],
                        ['value' => 'shipping', 'description' => 'Delivery and tracking problems.'],
                        ['value' => 'other', 'description' => 'Requests outside these departments.'],
                    ],
                ],
                [
                    'type' => 'score',
                    'name' => 'severity',
                    'instructions' => 'How severe is the reported problem?',
                    'levels' => [
                        ['label' => 'Cosmetic issue.'],
                        ['label' => 'Workaround available.'],
                        ['label' => 'Completely blocked.'],
                    ],
                ],
                ['type' => 'predicate', 'name' => 'refund', 'instructions' => 'Does the customer request a refund?'],
            ],
        ], $this->payload());
    }

    public function test_text_input_is_sent_as_a_string(): void
    {
        $classifier = $this->classifier(self::decision());

        $classifier->classify(new ClassificationRequest(
            'My parcel never arrived, refund me.',
            SupportTicket::request()->questions,
        ));

        self::assertSame('My parcel never arrived, refund me.', $this->payload()['input']);
    }

    /**
     * @param array<array-key, mixed> $input
     */
    #[DataProvider('json_inputs')]
    public function test_objects_and_lists_of_objects_are_sent_as_json_data(array $input): void
    {
        $classifier = $this->classifier('{"answers":[{"type":"predicate","name":"check","probability":0.5}]}');

        $classifier->classify(new ClassificationRequest($input, ['check' => new Boolean('True?')]));

        self::assertSame(
            [['role' => 'user', 'content' => [['type' => 'input_json', 'data' => $input]]]],
            $this->payload()['input'],
        );
    }

    public static function json_inputs(): iterable
    {
        yield 'list of objects' => [[['order' => 1], ['order' => 2]]];
        yield 'list wrapped in an object' => [['tags' => ['late', 'damaged']]];
    }

    /**
     * @param array<array-key, mixed> $input
     */
    #[DataProvider('invalid_inputs')]
    public function test_input_that_is_not_an_object_or_list_of_objects_fails_before_sending(array $input): void
    {
        // An empty mock queue throws before the history records anything, so a response is queued to make a send visible.
        $classifier = $this->classifier('{"answers":[{"type":"predicate","name":"check","probability":0.5}]}');

        try {
            $classifier->classify(new ClassificationRequest($input, ['check' => new Boolean('True?')]));
            self::fail('Expected the input to be rejected before sending.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'OpenAI Decisions accepts array input only as a JSON object or a list of objects. '
                .'Wrap other lists in an object under a descriptive key.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $this->sentRequests);
    }

    public static function invalid_inputs(): iterable
    {
        yield 'list of strings' => [['a', 'b']];
        yield 'list of numbers' => [[1, 2]];
        yield 'list of lists' => [[['x']]];
        yield 'list of empty arrays' => [[[]]];
        yield 'object followed by a scalar' => [[['a' => 1], 'b']];
    }

    public function test_levels_become_labels_and_null_descriptions_are_omitted(): void
    {
        $classifier = $this->classifier(self::decision([
            [
                'type' => 'choice',
                'name' => 'department',
                'choice' => 'other',
                'confidence' => 0.8,
                'probabilities' => [
                    ['value' => 'billing', 'probability' => 0.1],
                    ['value' => 'other', 'probability' => 0.9],
                ],
            ],
            [
                'type' => 'score',
                'name' => 'severity',
                'score' => 1,
                'confidence' => 1,
                'probabilities' => [
                    ['value' => 0, 'label' => 'Low', 'probability' => 0],
                    ['value' => 1, 'label' => 'High', 'probability' => 1],
                ],
            ],
        ]));

        $result = $classifier->classify(new ClassificationRequest('Input', [
            'department' => new Choice('Which department?', ['billing' => 'Charges and payment problems.', 'other' => null]),
            'severity' => new Score('How severe?', ['Low', 'High']),
        ]));

        self::assertSame('other', $result->choice('department')->choice);
        self::assertSame(1.0, $result->score('severity')->score);
        self::assertSame([
            [
                'type' => 'choice',
                'name' => 'department',
                'instructions' => 'Which department?',
                'choices' => [['value' => 'billing', 'description' => 'Charges and payment problems.'], ['value' => 'other']],
            ],
            [
                'type' => 'score',
                'name' => 'severity',
                'instructions' => 'How severe?',
                'levels' => [['label' => 'Low'], ['label' => 'High']],
            ],
        ], $this->payload()['questions']);
    }

    public function test_refusal_keeps_the_other_answers(): void
    {
        [$department, $severity] = self::fixture()['answers'];
        $classifier = $this->classifier(self::decision([$department, $severity, ['type' => 'refusal', 'name' => 'refund']]));

        $result = $classifier->classify(SupportTicket::request());

        self::assertInstanceOf(Refusal::class, $result->answers['refund']);
        self::assertTrue($result->isRefused('refund'));
        self::assertFalse($result->isRefused('department'));
        self::assertFalse($result->isRefused('severity'));
        self::assertSame('shipping', $result->choice('department')->choice);
        self::assertEqualsWithDelta(1.2, $result->score('severity')->score, 1e-12);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Classification answer 'refund' was refused by the provider.");

        $result->boolean('refund');
    }

    #[DataProvider('question_types')]
    public function test_every_question_can_be_refused(string $refused): void
    {
        $answers = [];
        foreach (self::fixture()['answers'] as $answer) {
            $answers[] = $answer['name'] === $refused ? ['type' => 'refusal', 'name' => $refused] : $answer;
        }

        $result = $this->classifier(self::decision($answers))->classify(SupportTicket::request());

        self::assertInstanceOf(Refusal::class, $result->answers[$refused]);
        foreach (array_keys($result->answers) as $id) {
            self::assertSame($id === $refused, $result->isRefused($id), "Question '{$id}'");
        }
    }

    public static function question_types(): iterable
    {
        yield 'choice' => ['department'];
        yield 'score' => ['severity'];
        yield 'predicate' => ['refund'];
    }

    public function test_score_probabilities_are_read_by_level_index_not_label(): void
    {
        [$department, $severity, $refund] = self::fixture()['answers'];
        $severity['probabilities'] = [
            ['value' => 2, 'label' => 'Cosmetic issue.', 'probability' => 0.625],
            ['value' => 0, 'label' => 'Completely blocked.', 'probability' => 0.125],
            ['value' => 1, 'label' => 'Workaround available.', 'probability' => 0.25],
        ];

        $result = $this->classifier(self::decision([$department, $severity, $refund]))->classify(SupportTicket::request());

        $probabilities = $result->score('severity')->distribution->probabilities;
        self::assertSame(0.125, $probabilities[0]);
        self::assertSame(0.25, $probabilities[1]);
        self::assertSame(0.625, $probabilities[2]);
        self::assertSame(1.5, $result->score('severity')->score);
    }

    public function test_choice_ties_follow_the_shared_definition_order(): void
    {
        [$department, $severity, $refund] = self::fixture()['answers'];
        $department['choice'] = 'other';
        $department['probabilities'] = [
            ['value' => 'other', 'probability' => 0.5],
            ['value' => 'shipping', 'probability' => 0],
            ['value' => 'billing', 'probability' => 0.5],
        ];

        $result = $this->classifier(self::decision([$department, $severity, $refund]))->classify(SupportTicket::request());

        self::assertSame('billing', $result->choice('department')->choice);
    }

    #[DataProvider('malformed_responses')]
    public function test_rejects_malformed_responses(string $body, string $message): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);

        $this->classifier($body)->classify(SupportTicket::request());
    }

    public static function malformed_responses(): iterable
    {
        [$department, $severity, $refund] = self::fixture()['answers'];
        $withDepartment = static fn (array $changes): string => self::decision([$changes + $department, $severity, $refund]);
        $withSeverity = static fn (array $changes): string => self::decision([$department, $changes + $severity, $refund]);
        $withRefund = static fn (array $changes): string => self::decision([$department, $severity, $changes + $refund]);
        $json = 'OpenAI Decisions returned invalid JSON.';
        $list = 'OpenAI Decisions response must list one answer per question.';
        $outcome = 'Each outcome must appear once, named by its option value or level index.';

        yield 'invalid json' => ['not json', $json];
        yield 'nested beyond 512 levels' => [str_repeat('[', 600).str_repeat(']', 600), $json];
        yield 'null body' => ['null', $list];
        yield 'scalar body' => ['42', $list];
        yield 'missing answers' => [json_encode(array_diff_key(self::fixture(), ['answers' => true]), JSON_THROW_ON_ERROR), $list];
        yield 'answers keyed by question id' => [
            self::decision(['department' => $department, 'severity' => $severity, 'refund' => $refund]),
            $list,
        ];
        yield 'too few answers' => [self::decision([$department, $severity]), $list];
        yield 'too many answers' => [
            self::decision([$department, $severity, $refund, ['type' => 'predicate', 'name' => 'escalate', 'probability' => 0.1]]),
            $list,
        ];
        yield 'swapped order' => [
            self::decision([$severity, $department, $refund]),
            "OpenAI Decisions answer 0 must answer question 'department'.",
        ];
        yield 'answer not an object' => [
            self::decision([$department, $severity, null]),
            "OpenAI Decisions answer 2 must answer question 'refund'.",
        ];
        yield 'missing name' => [
            self::decision([array_diff_key($department, ['name' => true]), $severity, $refund]),
            "OpenAI Decisions answer 0 must answer question 'department'.",
        ];
        yield 'different name' => [
            $withSeverity(['name' => 'urgency']),
            "OpenAI Decisions answer 1 must answer question 'severity'.",
        ];
        yield 'refusal named for the wrong question' => [
            self::decision([$department, $severity, ['type' => 'refusal', 'name' => 'department']]),
            "OpenAI Decisions answer 2 must answer question 'refund'.",
        ];
        yield 'unnamed refusal' => [
            self::decision([$department, $severity, ['type' => 'refusal', 'name' => null]]),
            "OpenAI Decisions answer 2 must answer question 'refund'.",
        ];
        yield 'wrong type' => [
            $withRefund(['type' => 'choice']),
            "OpenAI Decisions answer 'refund' must have type 'predicate' or 'refusal'.",
        ];
        yield 'System One type' => [
            $withRefund(['type' => 'noul']),
            "OpenAI Decisions answer 'refund' must have type 'predicate' or 'refusal'.",
        ];
        yield 'missing type' => [
            self::decision([array_diff_key($department, ['type' => true]), $severity, $refund]),
            "OpenAI Decisions answer 'department' must have type 'choice' or 'refusal'.",
        ];
        yield 'string probability' => [
            $withRefund(['probability' => '0.97']),
            "Invalid OpenAI Decisions answer 'refund': The predicate probability must be a number.",
        ];
        yield 'boolean probability' => [
            $withRefund(['probability' => true]),
            "Invalid OpenAI Decisions answer 'refund': The predicate probability must be a number.",
        ];
        yield 'missing probability' => [
            self::decision([$department, $severity, array_diff_key($refund, ['probability' => true])]),
            "Invalid OpenAI Decisions answer 'refund': The predicate probability must be a number.",
        ];
        yield 'out of range probability' => [
            $withRefund(['probability' => 1.2]),
            "Invalid OpenAI Decisions answer 'refund': Boolean probability must be finite and between zero and one.",
        ];
        yield 'probabilities not a list' => [
            $withDepartment(['probabilities' => ['billing' => 0.05, 'shipping' => 0.9, 'other' => 0.05]]),
            "Invalid OpenAI Decisions answer 'department': The probabilities field must list the outcomes.",
        ];
        yield 'missing probabilities' => [
            self::decision([$department, array_diff_key($severity, ['probabilities' => true]), $refund]),
            "Invalid OpenAI Decisions answer 'severity': The probabilities field must list the outcomes.",
        ];
        yield 'outcome not an object' => [
            $withDepartment(['probabilities' => [0.05, 0.9, 0.05]]),
            "Invalid OpenAI Decisions answer 'department': {$outcome}",
        ];
        yield 'repeated value' => [
            $withDepartment(['probabilities' => [
                ['value' => 'billing', 'probability' => 0.05],
                ['value' => 'billing', 'probability' => 0.05],
                ['value' => 'shipping', 'probability' => 0.9],
            ]]),
            "Invalid OpenAI Decisions answer 'department': {$outcome}",
        ];
        yield 'level value as a string' => [
            $withSeverity(['probabilities' => [
                ['value' => 0, 'label' => 'Cosmetic issue.', 'probability' => 0.1],
                ['value' => '1', 'label' => 'Workaround available.', 'probability' => 0.6],
                ['value' => 2, 'label' => 'Completely blocked.', 'probability' => 0.3],
            ]]),
            "Invalid OpenAI Decisions answer 'severity': {$outcome}",
        ];
        yield 'level value as a float' => [
            $withSeverity(['probabilities' => [
                ['value' => 0, 'label' => 'Cosmetic issue.', 'probability' => 0.1],
                ['value' => 1.0, 'label' => 'Workaround available.', 'probability' => 0.6],
                ['value' => 2, 'label' => 'Completely blocked.', 'probability' => 0.3],
            ]]),
            "Invalid OpenAI Decisions answer 'severity': {$outcome}",
        ];
        yield 'boolean choice value' => [
            $withDepartment(['probabilities' => [
                ['value' => 'billing', 'probability' => 0.05],
                ['value' => true, 'probability' => 0.9],
                ['value' => 'other', 'probability' => 0.05],
            ]]),
            "Invalid OpenAI Decisions answer 'department': {$outcome}",
        ];
        yield 'null choice value' => [
            $withDepartment(['probabilities' => [
                ['value' => 'billing', 'probability' => 0.05],
                ['value' => null, 'probability' => 0.9],
                ['value' => 'other', 'probability' => 0.05],
            ]]),
            "Invalid OpenAI Decisions answer 'department': {$outcome}",
        ];
        yield 'unknown option' => [
            $withDepartment(['probabilities' => [
                ['value' => 'billing', 'probability' => 0.05],
                ['value' => 'shipping', 'probability' => 0.9],
                ['value' => 'refunds', 'probability' => 0.05],
            ]]),
            "Invalid OpenAI Decisions answer 'department': Choice probabilities must cover exactly the defined options.",
        ];
        yield 'missing option' => [
            $withDepartment(['probabilities' => [
                ['value' => 'billing', 'probability' => 0.1],
                ['value' => 'shipping', 'probability' => 0.9],
            ]]),
            "Invalid OpenAI Decisions answer 'department': Choice probabilities must cover exactly the defined options.",
        ];
        yield 'missing level' => [
            $withSeverity(['probabilities' => [
                ['value' => 0, 'label' => 'Cosmetic issue.', 'probability' => 0.4],
                ['value' => 1, 'label' => 'Workaround available.', 'probability' => 0.6],
            ]]),
            "Invalid OpenAI Decisions answer 'severity': Score probabilities must cover exactly the defined levels.",
        ];
        yield 'string outcome probability' => [
            $withDepartment(['probabilities' => [
                ['value' => 'billing', 'probability' => '0.05'],
                ['value' => 'shipping', 'probability' => 0.9],
                ['value' => 'other', 'probability' => 0.05],
            ]]),
            "Invalid OpenAI Decisions answer 'department': Probabilities must be finite numbers between zero and one.",
        ];
        yield 'sum off by more than 1e-3' => [
            $withDepartment(['probabilities' => [
                ['value' => 'billing', 'probability' => 0.05],
                ['value' => 'shipping', 'probability' => 0.9],
                ['value' => 'other', 'probability' => 0.052],
            ]]),
            "Invalid OpenAI Decisions answer 'department': Probabilities must sum to one.",
        ];
    }

    /**
     * @param class-string<Throwable> $cause
     */
    #[DataProvider('parse_failures')]
    public function test_parse_failures_keep_the_original_exception(string $body, string $cause): void
    {
        try {
            $this->classifier($body)->classify(SupportTicket::request());
            self::fail('Expected the response to be rejected.');
        } catch (ProviderException $exception) {
            self::assertInstanceOf($cause, $exception->getPrevious());
        }
    }

    public static function parse_failures(): iterable
    {
        [$department, $severity, $refund] = self::fixture()['answers'];

        yield 'invalid json' => ['not json', JsonException::class];
        yield 'invalid value' => [
            self::decision([$department, $severity, ['probability' => 1.2] + $refund]),
            InvalidArgumentException::class,
        ];
    }

    /**
     * @param array<string, Choice|Score|Boolean> $questions
     */
    #[DataProvider('oversized_questions')]
    public function test_provider_limits_fail_before_sending(array $questions, string $message): void
    {
        // An empty mock queue throws before the history records anything, so a response is queued to make a send visible.
        $classifier = $this->classifier('{"answers":[]}');

        try {
            $classifier->classify(new ClassificationRequest('Input', $questions));
            self::fail('Expected the request to be rejected before sending.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertSame([], $this->sentRequests);
    }

    public static function oversized_questions(): iterable
    {
        $questions = [];
        $options = [];
        for ($index = 0; $index < 401; $index++) {
            $questions['question_'.$index] = new Boolean('Is statement '.$index.' true?');
        }
        for ($index = 0; $index < 256; $index++) {
            $options['option_'.$index] = 'Option '.$index;
        }

        yield '401 questions' => [
            $questions,
            'OpenAI Decisions accepts at most 400 questions per request.',
        ];
        yield '256 choices' => [
            ['oversized' => new Choice('Which?', $options)],
            "OpenAI Decisions choice 'oversized' supports at most 255 options.",
        ];
        yield '11 levels' => [
            ['oversized' => new Score('How much?', array_fill(0, 11, 'Level'))],
            "OpenAI Decisions score 'oversized' supports at most 10 levels.",
        ];
    }

    public function test_questions_at_the_provider_limits_are_supported(): void
    {
        $questions = [];
        $answers = [];
        for ($index = 0; $index < 398; $index++) {
            $questions['question_'.$index] = new Boolean('Is statement '.$index.' true?');
            $answers[] = ['type' => 'predicate', 'name' => 'question_'.$index, 'probability' => 0.5];
        }
        $options = [];
        $choices = [];
        for ($index = 0; $index < 255; $index++) {
            $options['option_'.$index] = 'Option '.$index;
            $choices[] = ['value' => 'option_'.$index, 'probability' => $index === 254 ? 1 : 0];
        }
        $questions['choice'] = new Choice('Which option applies?', $options);
        $answers[] = ['type' => 'choice', 'name' => 'choice', 'choice' => 'option_254', 'confidence' => 1, 'probabilities' => $choices];
        $levels = array_map(static fn (int $level): string => 'Level '.$level, range(0, 9));
        $questions['score'] = new Score('How much?', $levels);
        $answers[] = [
            'type' => 'score',
            'name' => 'score',
            'score' => 9,
            'confidence' => 1,
            'probabilities' => array_map(
                static fn (int $level): array => ['value' => $level, 'label' => 'Level '.$level, 'probability' => $level === 9 ? 1 : 0],
                range(0, 9),
            ),
        ];
        $classifier = $this->classifier(self::decision($answers));

        $result = $classifier->classify(new ClassificationRequest('Input', $questions));

        self::assertSame(0.5, $result->boolean('question_0')->probability);
        self::assertSame('option_254', $result->choice('choice')->choice);
        self::assertSame(9.0, $result->score('score')->score);
        $sent = $this->payload()['questions'];
        self::assertCount(400, $sent);
        self::assertCount(255, $sent[398]['choices']);
        self::assertCount(10, $sent[399]['levels']);
    }

    public function test_calls_do_not_leak_questions_input_or_credentials(): void
    {
        $client = $this->client(
            '{"answers":[{"type":"predicate","name":"first","probability":0}]}',
            '{"answers":[{"type":"predicate","name":"second","probability":1}]}',
            '{"answers":[{"type":"predicate","name":"first","probability":0}]}',
        );
        $first = new OpenAIDecisions(key: 'first-key', httpClient: $client);
        $second = new OpenAIDecisions(key: 'second-key', httpClient: $client);

        $first->classify(new ClassificationRequest('First input', ['first' => new Boolean('First?')]));
        $result = $second->classify(new ClassificationRequest(['ticket' => 'Second input'], ['second' => new Boolean('Second?')]));
        $first->classify(new ClassificationRequest('Third input', ['first' => new Boolean('Third?')]));

        self::assertSame(1.0, $result->boolean('second')->probability);
        self::assertSame('Bearer first-key', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer second-key', $this->sentRequests[1]['request']->getHeaderLine('Authorization'));
        self::assertSame('Bearer first-key', $this->sentRequests[2]['request']->getHeaderLine('Authorization'));
        $payload = $this->payload(1);
        self::assertSame(
            [['role' => 'user', 'content' => [['type' => 'input_json', 'data' => ['ticket' => 'Second input']]]]],
            $payload['input'],
        );
        self::assertSame([['type' => 'predicate', 'name' => 'second', 'instructions' => 'Second?']], $payload['questions']);
        $payload = $this->payload(2);
        self::assertSame('Third input', $payload['input']);
        self::assertSame([['type' => 'predicate', 'name' => 'first', 'instructions' => 'Third?']], $payload['questions']);
    }

    public function test_http_client_replacement_preserves_authentication_and_custom_model(): void
    {
        $classifier = (new OpenAIDecisions(key: 'test-key', model: 'gpt-6-luna-2026-10-01'))->setHttpClient($this->client(
            '{"answers":[{"type":"predicate","name":"check","probability":0}]}',
        ));

        $result = $classifier->classify(new ClassificationRequest('Plain text', ['check' => new Boolean('True?')]));

        self::assertSame(0.0, $result->boolean('check')->probability);
        self::assertSame('Bearer test-key', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        self::assertSame('https://api.openai.com/v1/decisions', (string) $this->sentRequests[0]['request']->getUri());
        self::assertSame('gpt-6-luna-2026-10-01', $this->payload()['model']);
        self::assertSame('Plain text', $this->payload()['input']);
    }

    #[DataProvider('http_errors')]
    public function test_http_errors_preserve_the_response_and_never_expose_the_key(int $status): void
    {
        $body = '{"error":{"message":"Request failed.","type":"invalid_request_error","param":null,"code":null}}';
        $classifier = new OpenAIDecisions('sk-live-secret', httpClient: $this->recordingClient(new Response($status, body: $body)));

        try {
            $classifier->classify(SupportTicket::request());
            self::fail('Expected an HTTP exception.');
        } catch (HttpException $exception) {
            self::assertNotNull($exception->response);
            self::assertSame($status, $exception->response->statusCode);
            self::assertSame($body, $exception->response->body);
            self::assertStringNotContainsString('sk-live-secret', $exception->getMessage());
        }
    }

    public static function http_errors(): iterable
    {
        yield [400];
        yield [401];
        yield [429];
        yield [500];
    }

    #[DataProvider('invalid_configuration')]
    public function test_rejects_invalid_configuration(string $key, string $model, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new OpenAIDecisions($key, $model);
    }

    public static function invalid_configuration(): iterable
    {
        yield 'missing key' => ['', 'gpt-6-luna', 'OpenAI Decisions requires a non-empty API key.'];
        yield 'blank key' => [' ', 'gpt-6-luna', 'OpenAI Decisions requires a non-empty API key.'];
        yield 'missing model' => ['test-key', '', 'OpenAI Decisions requires a non-empty model name.'];
        yield 'blank model' => ['test-key', "\t ", 'OpenAI Decisions requires a non-empty model name.'];
    }

    /**
     * A client answering with the given bodies in turn and recording each request it sends.
     */
    protected function client(string ...$bodies): GuzzleHttpClient
    {
        return $this->recordingClient(...array_map(static fn (string $body): Response => new Response(200, body: $body), $bodies));
    }

    protected function classifier(string ...$bodies): OpenAIDecisions
    {
        return new OpenAIDecisions('test-key', httpClient: $this->client(...$bodies));
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(int $call = 0): array
    {
        return json_decode((string) $this->sentRequests[$call]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/fixtures/decision.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The fixture's envelope around the given answers, or around its own answers when none are given.
     * Zero fractions are kept so a float sent by a test stays a float on the wire.
     */
    protected static function decision(?array $answers = null): string
    {
        $decision = $answers === null ? self::fixture() : ['answers' => $answers] + self::fixture();

        return json_encode($decision, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
