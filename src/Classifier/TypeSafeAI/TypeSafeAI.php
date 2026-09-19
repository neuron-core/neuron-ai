<?php

declare(strict_types=1);

namespace NeuronAI\Classifier\TypeSafeAI;

use InvalidArgumentException;
use JsonException;
use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\BooleanResult;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ChoiceResult;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\ClassificationResult;
use NeuronAI\Classifier\ClassifierInterface;
use NeuronAI\Classifier\Score;
use NeuronAI\Classifier\ScoreResult;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;

use function array_diff_key;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function json_decode;
use function json_encode;
use function trim;

use const JSON_THROW_ON_ERROR;

class TypeSafeAI implements ClassifierInterface
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.typesafe.ai/v1';

    public function __construct(
        protected string $key,
        protected string $model = 'jev-latest',
        ?HttpClientInterface $httpClient = null,
    ) {
        if (trim($key) === '') {
            throw new InvalidArgumentException('TypeSafeAI requires a non-empty API key.');
        }

        if (trim($model) === '') {
            throw new InvalidArgumentException('TypeSafeAI requires a non-empty model name.');
        }

        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    /**
     * @throws ProviderException
     * @throws HttpException
     * @throws JsonException
     */
    public function classify(ClassificationRequest $request): ClassificationResult
    {
        $questions = [];

        foreach ($request->questions as $id => $question) {
            $questions[$id] = $this->mapQuestion($id, $question);
        }

        $response = $this->httpClient->request(new HttpRequest(
            method: HttpMethod::POST,
            uri: $this->baseUri.'/systemone',
            headers: [
                'Authorization' => 'Bearer '.$this->key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            // Structured state may contain "contents"; force JSON instead of multipart inference.
            body: json_encode([
                'model' => $this->model,
                'state' => $request->input,
                'questions' => $questions,
            ], JSON_THROW_ON_ERROR),
        ));

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException('TypeSafeAI returned invalid JSON.', 0, $exception);
        }

        if (!is_array($data) || !isset($data['answers']) || !is_array($data['answers'])) {
            throw new ProviderException('TypeSafeAI response must contain an answers map.');
        }

        if (count($data['answers']) !== count($request->questions)
            || array_diff_key($request->questions, $data['answers']) !== []) {
            throw new ProviderException('TypeSafeAI answers must match the requested question identifiers.');
        }

        $answers = [];

        foreach ($request->questions as $id => $question) {
            $answers[$id] = $this->mapAnswer($id, $question, $data['answers'][$id]);
        }

        return new ClassificationResult($request, $answers);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapQuestion(string $id, Choice|Score|Boolean $question): array
    {
        $mapped = [
            'type' => $this->questionType($question),
            'instructions' => $question->instructions,
        ];

        if ($question instanceof Choice) {
            if (count($question->options) > 255) {
                throw new InvalidArgumentException("TypeSafeAI choice '{$id}' supports at most 255 options.");
            }

            $mapped['criteria'] = $question->options;
        } elseif ($question instanceof Score) {
            if (count($question->levels) > 10) {
                throw new InvalidArgumentException("TypeSafeAI score '{$id}' supports at most 10 levels.");
            }

            $mapped['criteria'] = $question->levels;
        }

        return $mapped;
    }

    /**
     * @throws ProviderException
     */
    protected function mapAnswer(string $id, Choice|Score|Boolean $question, mixed $answer): ChoiceResult|ScoreResult|BooleanResult
    {
        $type = $this->questionType($question);

        if (!is_array($answer) || ($answer['type'] ?? null) !== $type) {
            throw new ProviderException("TypeSafeAI answer '{$id}' must have type '{$type}'.");
        }

        try {
            if ($question instanceof Boolean) {
                $probability = $answer['noul'] ?? null;

                if (!is_int($probability) && !is_float($probability)) {
                    throw new InvalidArgumentException('The noul probability must be a number.');
                }

                return new BooleanResult($question, $probability);
            }

            $probabilities = $answer['probabilities'] ?? null;

            if (!is_array($probabilities)) {
                throw new InvalidArgumentException('The probabilities field must contain a distribution.');
            }

            return $question instanceof Choice
                ? new ChoiceResult($question, $probabilities)
                : new ScoreResult($question, $probabilities);
        } catch (InvalidArgumentException $exception) {
            throw new ProviderException("Invalid TypeSafeAI answer '{$id}': {$exception->getMessage()}", 0, $exception);
        }
    }

    protected function questionType(Choice|Score|Boolean $question): string
    {
        return match (true) {
            $question instanceof Choice => 'choice',
            $question instanceof Score => 'score',
            $question instanceof Boolean => 'noul',
        };
    }
}
