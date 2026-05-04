<?php

declare(strict_types=1);

namespace Flowline\Laravel;

use Flowline\Client;
use Flowline\Http\Request as FlowlineRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as LaravelRequest;

final class FlowlineController
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function __invoke(LaravelRequest $request): JsonResponse
    {
        $flowlineRequest = new FlowlineRequest(
            method: $request->method(),
            headers: $request->headers->all(),
            body: $request->getContent(),
            query: $request->query->all(),
        );

        $response = $this->client->handler()->handle($flowlineRequest);

        return new JsonResponse($response->body, $response->statusCode, $response->headers);
    }
}
