<?php

declare(strict_types=1);

namespace NeuronAI\Classifier\Cloudflare;

use InvalidArgumentException;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\ClassificationResult;
use NeuronAI\Classifier\SystemOne\SystemOne;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;

use function array_keys;
use function count;
use function is_array;
use function preg_match;
use function rawurlencode;
use function rtrim;
use function trim;

/**
 * Cloudflare's Clef decision models on Workers AI, which serve the System One protocol through the REST /ai/run endpoint.
 */
class WorkersAI extends SystemOne
{
    protected string $baseUri = 'https://api.cloudflare.com/client/v4';

    /**
     * @param string $model "clef" or "clef-flash": selects @cf/cloudflare/{model} and is repeated as the body model.
     */
    public function __construct(
        protected string $accountId,
        string $key,
        string $model = 'clef',
        ?HttpClientInterface $httpClient = null,
    ) {
        if (trim($accountId) === '') {
            throw new InvalidArgumentException('Workers AI requires a non-empty account ID.');
        }

        parent::__construct($this->baseUri, $model, $key, $httpClient);
    }

    public function classify(ClassificationRequest $request): ClassificationResult
    {
        if (count($request->questions) > 64) {
            throw new InvalidArgumentException('Workers AI accepts at most 64 questions per request.');
        }

        foreach (array_keys($request->questions) as $id) {
            if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $id) !== 1) {
                throw new InvalidArgumentException(
                    "Workers AI question identifier '{$id}' must be 1 to 100 letters, digits, '_', '.' or '-'."
                );
            }
        }

        return parent::classify($request);
    }

    protected function endpoint(): string
    {
        return rtrim($this->baseUri, '/')
            .'/accounts/'.rawurlencode($this->accountId)
            .'/ai/run/@cf/cloudflare/'.rawurlencode($this->model);
    }

    protected function unwrap(mixed $payload): mixed
    {
        if (!is_array($payload) || !is_array($payload['result'] ?? null)) {
            throw new ProviderException('Workers AI response must contain a result object.');
        }

        return $payload['result'];
    }

    protected function name(): string
    {
        return 'Workers AI';
    }
}
