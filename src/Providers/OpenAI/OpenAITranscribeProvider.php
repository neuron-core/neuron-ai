<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Transcribe\TranscribeProviderInterface;

class OpenAITranscribeProvider implements TranscribeProviderInterface
{
    protected Client $client;

    protected string $baseUri = 'https://api.openai.com/v1/audio/transcriptions';

    public function __construct(
        protected string $key,
        protected string $model,
        protected string $language = 'en',
    ) {
        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]
        ]);
    }

    public function trascribe(string $filePath): string
    {
        // Preparar los datos del formulario
        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath)
            ],
            [
                'name' => 'model',
                'contents' => 'whisper-1'
            ],
            [
                'name' => 'language',
                'contents' => $this->language
            ],
            [
                'name' => 'response_format',
                'contents' => 'json'
            ]
        ];
        $response = $this->client->post('', [
            'multipart' => $multipart,
            RequestOptions::TIMEOUT => 60,
            RequestOptions::CONNECT_TIMEOUT => 30
        ]);

        // Procesar la respuesta
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        $result = json_decode($body, true);

        if ($statusCode !== 200) {
            throw new ProviderException('Whisper API error: ' . $body);
        }

        return $result['text'] ?? '';
    }
}
