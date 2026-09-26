<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Stub;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use Psr\Http\Message\RequestInterface;

use function count;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/** Queues JSON responses and exposes the JSON requests the unit under test sent. */
trait RecordsJsonRequests
{
    use RecordsHttpRequests;

    /** @param array<string, mixed> $body */
    protected function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    protected function sentRequest(int $index = 0): RequestInterface
    {
        $this->assertGreaterThan($index, count($this->sentRequests), "Request #{$index} was never sent.");

        return $this->sentRequests[$index]['request'];
    }

    /** @return array<string, mixed> */
    protected function sentJson(int $index = 0): array
    {
        return json_decode((string) $this->sentRequest($index)->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
