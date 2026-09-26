<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;

class MeilisearchTaskReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    public function test_a_failed_settings_task_is_reported_without_polling_again(): void
    {
        $client = $this->recordingClient(
            new Response(200),
            $this->jsonResponse(['taskUid' => 1]),
            $this->jsonResponse(['status' => 'failed', 'error' => ['message' => 'invalid embedder']]),
            $this->jsonResponse(['taskUid' => 2]),
            $this->jsonResponse(['status' => 'succeeded']),
        );

        try {
            new MeilisearchVectorStore('docs', host: 'http://meili.test:7700', httpClient: $client);
            $this->fail('A failed Meilisearch task must not be accepted.');
        } catch (VectorStoreException $exception) {
            $this->assertStringContainsString('invalid embedder', $exception->getMessage());
        }

        $this->assertSame([
            'GET http://meili.test:7700/indexes/docs',
            'PATCH http://meili.test:7700/indexes/docs/settings/embedders',
            'GET http://meili.test:7700/tasks/1',
        ], $this->sentTargets());
    }

    public function test_an_unauthorized_index_lookup_is_not_treated_as_a_missing_index(): void
    {
        $client = $this->recordingClient(
            $this->jsonResponse(['code' => 'invalid_api_key'], 403),
            $this->jsonResponse(['taskUid' => 1], 202),
            $this->jsonResponse(['status' => 'succeeded']),
            $this->jsonResponse(['taskUid' => 2]),
            $this->jsonResponse(['status' => 'succeeded']),
            $this->jsonResponse(['taskUid' => 3]),
            $this->jsonResponse(['status' => 'succeeded']),
        );

        try {
            new MeilisearchVectorStore('docs', host: 'http://meili.test:7700', key: 'wrong', httpClient: $client);
            $this->fail('An authorization failure must surface.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->response?->statusCode);
        }

        $this->assertSame(['GET http://meili.test:7700/indexes/docs'], $this->sentTargets());
    }
}
