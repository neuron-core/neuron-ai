<?php

declare(strict_types=1);

namespace NeuronAI\Classifier\OpenAI;

use InvalidArgumentException;
use JsonException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\BooleanResult;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ChoiceResult;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\ClassificationResult;
use NeuronAI\Classifier\ClassifierInterface;
use NeuronAI\Classifier\Refusal;
use NeuronAI\Classifier\Score;
use NeuronAI\Classifier\ScoreResult;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function trim;

use const JSON_THROW_ON_ERROR;

class OpenAIDecisions implements ClassifierInterface
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.openai.com/v1';

    public function __construct(
        protected string $key,
        protected string $model = 'gpt-6-luna',
        ?HttpClientInterface $httpClient = null,
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('OpenAI Decisions requires a non-empty API key.');
        }

        if (trim($model) === '') {
            throw new InvalidArgumentException('OpenAI Decisions requires a non-empty model name.');
        }

        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * @throws InvalidArgumentException
     * @throws ProviderException
     * @throws HttpException
     * @throws JsonException
     */
    public function classify(ClassificationRequest $request): ClassificationResult
    {
        if (count($request->questions) > 400) {
            throw new InvalidArgumentException('OpenAI Decisions accepts at most 400 questions per request.');
        }

        $questions = [];

        foreach ($request->questions as $id => $question) {
            $questions[] = $this->mapQuestion($id, $question);
        }

        $response = $this->httpClient->request(new HttpRequest(
            method: HttpMethod::POST,
            uri: rtrim($this->baseUri, '/').'/decisions',
            headers: [
                'Authorization' => 'Bearer '.$this->key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            body: json_encode([
                'model' => $this->model,
                'input' => $this->mapInput($request->input),
                'questions' => $questions,
            ], JSON_THROW_ON_ERROR),
        ));

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException('OpenAI Decisions returned invalid JSON.', 0, $exception);
        }

        $answers = is_array($data) ? ($data['answers'] ?? null) : null;

        if (!is_array($answers) || !array_is_list($answers) || count($answers) !== count($request->questions)) {
            throw new ProviderException('OpenAI Decisions response must list one answer per question.');
        }

        $results = [];

        // Answers come back in question order and echo each question's name; both must agree.
        foreach (array_keys($request->questions) as $position => $id) {
            $answer = $answers[$position];

            if (!is_array($answer) || ($answer['name'] ?? null) !== $id) {
                throw new ProviderException("OpenAI Decisions answer {$position} must answer question '{$id}'.");
            }

            $results[$id] = $this->mapAnswer($id, $request->questions[$id], $answer);
        }

        return new ClassificationResult($request, $results);
    }

    /**
     * @param string|array<array-key, mixed> $input
     * @return string|list<array<string, mixed>>
     */
    protected function mapInput(string|array $input): string|array
    {
        if (is_string($input)) {
            return $input;
        }

        if (!$this->isObjectOrObjectList($input)) {
            throw new InvalidArgumentException(
                'OpenAI Decisions accepts array input only as a JSON object or a list of objects. '
                .'Wrap other lists in an object under a descriptive key.'
            );
        }

        return [['role' => 'user', 'content' => [['type' => 'input_json', 'data' => $input]]]];
    }

    /**
     * The JSON shapes an input_json part accepts.
     *
     * @param array<array-key, mixed> $input
     */
    protected function isObjectOrObjectList(array $input): bool
    {
        if (!array_is_list($input)) {
            return true;
        }

        foreach ($input as $item) {
            if (!is_array($item) || array_is_list($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapQuestion(string $id, Choice|Score|Boolean $question): array
    {
        $mapped = [
            'type' => $this->questionType($question),
            'name' => $id,
            'instructions' => $question->instructions,
        ];

        if ($question instanceof Choice) {
            if (count($question->options) > 255) {
                throw new InvalidArgumentException("OpenAI Decisions choice '{$id}' supports at most 255 options.");
            }

            $mapped['choices'] = [];

            foreach ($question->options as $value => $description) {
                $mapped['choices'][] = $description === null
                    ? ['value' => $value]
                    : ['value' => $value, 'description' => $description];
            }
        } elseif ($question instanceof Score) {
            if (count($question->levels) > 10) {
                throw new InvalidArgumentException("OpenAI Decisions score '{$id}' supports at most 10 levels.");
            }

            $mapped['levels'] = array_map(static fn (string $level): array => ['label' => $level], $question->levels);
        }

        return $mapped;
    }

    /**
     * @param array<array-key, mixed> $answer
     * @throws ProviderException
     */
    protected function mapAnswer(string $id, Choice|Score|Boolean $question, array $answer): ChoiceResult|ScoreResult|BooleanResult|Refusal
    {
        $type = $answer['type'] ?? null;

        if ($type === 'refusal') {
            return new Refusal();
        }

        $expected = $this->questionType($question);

        if ($type !== $expected) {
            throw new ProviderException("OpenAI Decisions answer '{$id}' must have type '{$expected}' or 'refusal'.");
        }

        try {
            if ($question instanceof Boolean) {
                $probability = $answer['probability'] ?? null;

                if (!is_int($probability) && !is_float($probability)) {
                    throw new InvalidArgumentException('The predicate probability must be a number.');
                }

                return new BooleanResult($question, $probability);
            }

            $probabilities = $this->distribution($question, $answer['probabilities'] ?? null);

            return $question instanceof Choice
                ? new ChoiceResult($question, $probabilities)
                : new ScoreResult($question, $probabilities);
        } catch (InvalidArgumentException $exception) {
            throw new ProviderException("Invalid OpenAI Decisions answer '{$id}': {$exception->getMessage()}", 0, $exception);
        }
    }

    /**
     * OpenAI lists the outcomes as {value, probability} entries; the shared results take them keyed by
     * option value for a Choice and by level index for a Score.
     *
     * @return array<array-key, mixed>
     */
    protected function distribution(Choice|Score $question, mixed $entries): array
    {
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new InvalidArgumentException('The probabilities field must list the outcomes.');
        }

        $probabilities = [];

        foreach ($entries as $entry) {
            $value = is_array($entry) ? ($entry['value'] ?? null) : null;

            // A level index sent as "1" would become the integer key 1, so the type is checked before it becomes a key.
            if ((!is_int($value) && !is_string($value))
                || is_int($value) !== $question instanceof Score
                || array_key_exists($value, $probabilities)) {
                throw new InvalidArgumentException('Each outcome must appear once, named by its option value or level index.');
            }

            $probabilities[$value] = $entry['probability'] ?? null;
        }

        return $probabilities;
    }

    protected function questionType(Choice|Score|Boolean $question): string
    {
        return match (true) {
            $question instanceof Choice => 'choice',
            $question instanceof Score => 'score',
            $question instanceof Boolean => 'predicate',
        };
    }
}
