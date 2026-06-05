<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Cloud;

use NeuronAI\Cloud\CloudClient;
use NeuronAI\HttpClient\HttpResponse;

/**
 * A recording CloudClient that captures payloads instead of sending HTTP requests.
 */
class RecordingCloudClient extends CloudClient
{
    /** @var array<int, array{method: string, payload: array<string, mixed>}> */
    public array $calls = [];

    public function __construct()
    {
        // Do not call parent — avoid creating a real HTTP client
    }

    public function sendTrace(array $payload): HttpResponse
    {
        $this->calls[] = ['method' => 'sendTrace', 'payload' => $payload];

        return new HttpResponse(202, '{}', []);
    }

    public function sendEvaluation(array $payload): HttpResponse
    {
        $this->calls[] = ['method' => 'sendEvaluation', 'payload' => $payload];

        return new HttpResponse(202, '{}', []);
    }
}
