<?php

declare(strict_types=1);

namespace Flowline\Http;

use Flowline\Client;
use Flowline\Context;
use Flowline\Protocol\CallRequest;
use Flowline\Step;
use Flowline\StepPendingException;

/**
 * Processes incoming HTTP requests from the platform.
 *
 * Routes by HTTP method:
 *   GET  → introspection/health check
 *   PUT  → sync trigger (register tasks with platform)
 *   POST → call request (execute a task/step)
 */
final class Handler
{
    public function __construct(
        private readonly Client $client,
    ) {}

    public function handle(Request $request): Response
    {
        $sdkHeader = ['X-Flowline-SDK' => $this->client->sdkHeader()];

        if (! $this->verifySignature($request)) {
            return new Response(401, ['error' => 'Invalid signature'], $sdkHeader);
        }

        return match (strtoupper($request->method)) {
            'GET' => $this->introspect($sdkHeader),
            'PUT' => $this->sync($sdkHeader),
            'POST' => $this->call($request, $sdkHeader),
            default => new Response(405, ['error' => 'Method not allowed'], $sdkHeader),
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private function introspect(array $headers): Response
    {
        return new Response(200, [
            'mode' => 'cloud',
            'functionCount' => count($this->client->getTasks()),
            'sdk' => $this->client->sdkHeader(),
        ], $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function sync(array $headers): Response
    {
        $payload = $this->client->buildRegistrationPayload();

        return new Response(200, [
            'message' => 'Sync completed',
            'modified' => true,
            'payload' => $payload->toArray(),
        ], $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(Request $request, array $headers): Response
    {
        $functionId = $request->query['fnId'] ?? '';
        $stepId = $request->query['stepId'] ?? 'step';

        if ($functionId === '') {
            return new Response(400, ['error' => 'Missing fnId parameter'], $headers);
        }

        $task = $this->client->getTask($functionId);
        if ($task === null) {
            return new Response(404, ['error' => "Function not found: {$functionId}"], $headers);
        }

        $payload = json_decode($request->body, true) ?? [];
        $callRequest = CallRequest::fromPayload($functionId, $stepId, $payload);

        $step = new Step($callRequest->steps);

        try {
            $context = new Context(
                event: $callRequest->event,
                step: $step,
                runId: $callRequest->runId,
                attempt: $callRequest->attempt,
                events: $callRequest->events,
            );

            $result = ($task->handler)($context);

            return new Response(200, $result, $headers);
        } catch (StepPendingException) {
            $ops = $step->getOps();
            if (empty($ops)) {
                return new Response(200, null, $headers);
            }
            return new Response(206, $ops, $headers);
        } catch (\Throwable $e) {
            return new Response(500, [
                'error' => $e->getMessage(),
                'name' => get_class($e),
            ], array_merge($headers, ['X-Flowline-No-Retry' => 'false']));
        }
    }

    private function verifySignature(Request $request): bool
    {
        if ($this->client->signingKey === null) {
            return true;
        }

        $headerValue = $request->headers['x-flowline-signature']
            ?? $request->headers['X-Flowline-Signature']
            ?? null;

        if ($headerValue === null) {
            return false;
        }

        if (is_array($headerValue)) {
            $headerValue = $headerValue[0];
        }

        $expected = hash_hmac('sha256', $request->body, $this->client->signingKey);

        return hash_equals($expected, $headerValue);
    }
}
