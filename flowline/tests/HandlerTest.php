<?php

declare(strict_types=1);

namespace Flowline\Tests;

use Flowline\Client;
use Flowline\Context;
use Flowline\Event;
use Flowline\Http\Handler;
use Flowline\Http\Request;
use Flowline\StepPendingException;
use Flowline\Task;
use PHPUnit\Framework\TestCase;

final class HandlerTest extends TestCase
{
    private Client $client;
    private Handler $handler;

    protected function setUp(): void
    {
        $this->client = new Client(
            appName: 'test-app',
            serveUrl: 'https://example.com/api/flowline',
            eventKey: 'test-event-key',
        );
        $this->handler = $this->client->handler();
    }

    public function testGetIntrospection(): void
    {
        $this->client->register(new Task(
            id: 'my-task',
            triggers: [new Event(name: 'test/event')],
            handler: fn () => null,
        ));

        $response = $this->handler->handle(new Request(
            method: 'GET',
            headers: [],
            body: '',
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('cloud', $response->body['mode']);
        $this->assertSame(1, $response->body['functionCount']);
        $this->assertArrayHasKey('X-Flowline-SDK', $response->headers);
    }

    public function testPutSync(): void
    {
        $this->client->register(new Task(
            id: 'my-task',
            triggers: [new Event(name: 'test/event')],
            handler: fn () => null,
        ));

        $response = $this->handler->handle(new Request(
            method: 'PUT',
            headers: [],
            body: '',
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->body['modified']);
        $this->assertArrayHasKey('payload', $response->body);
        $this->assertCount(1, $response->body['payload']['functions']);
    }

    public function testPostCallReturnsStepRun(): void
    {
        $this->client->register(new Task(
            id: 'process-order',
            triggers: [new Event(name: 'order/created')],
            handler: function (Context $ctx): void {
                $ctx->step->run('fetch-order', fn (): array => ['id' => 42]);
            },
        ));

        $response = $this->handler->handle(new Request(
            method: 'POST',
            headers: [],
            body: json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'order/created', 'data' => []],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
            query: ['fnId' => 'test-app-process-order', 'stepId' => 'step'],
        ));

        $this->assertSame(206, $response->statusCode);
        $this->assertCount(1, $response->body);
        $this->assertSame('StepRun', $response->body[0]['op']);
        $this->assertSame('fetch-order', $response->body[0]['displayName']);
        $this->assertSame(['id' => 42], $response->body[0]['data']);
    }

    public function testPostCallReplaysMemoizedSteps(): void
    {
        $callCount = 0;
        $this->client->register(new Task(
            id: 'process-order',
            triggers: [new Event(name: 'order/created')],
            handler: function (Context $ctx) use (&$callCount): void {
                $ctx->step->run('step-1', function () use (&$callCount): string {
                    $callCount++;
                    return 'executed';
                });
                $ctx->step->run('step-2', function () use (&$callCount): string {
                    $callCount++;
                    return 'also-executed';
                });
            },
        ));

        $response = $this->handler->handle(new Request(
            method: 'POST',
            headers: [],
            body: json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'order/created', 'data' => []],
                'steps' => [sha1('step-1') => ['data' => 'memoized-result']],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
            query: ['fnId' => 'test-app-process-order', 'stepId' => 'step'],
        ));

        $this->assertSame(206, $response->statusCode);
        $this->assertSame('step-2', $response->body[0]['displayName']);
        $this->assertSame('also-executed', $response->body[0]['data']);
        $this->assertSame(1, $callCount);
    }

    public function testPostCallReturns200WhenFunctionCompletes(): void
    {
        $this->client->register(new Task(
            id: 'simple-task',
            triggers: [new Event(name: 'test/event')],
            handler: fn (Context $ctx): string => 'done',
        ));

        $response = $this->handler->handle(new Request(
            method: 'POST',
            headers: [],
            body: json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'test/event', 'data' => []],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
            query: ['fnId' => 'test-app-simple-task', 'stepId' => 'step'],
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('done', $response->body);
    }

    public function testPostCallReturns404ForUnknownFunction(): void
    {
        $response = $this->handler->handle(new Request(
            method: 'POST',
            headers: [],
            body: '{}',
            query: ['fnId' => 'nonexistent', 'stepId' => 'step'],
        ));

        $this->assertSame(404, $response->statusCode);
    }

    public function testPostCallReturns400ForMissingFnId(): void
    {
        $response = $this->handler->handle(new Request(
            method: 'POST',
            headers: [],
            body: '{}',
            query: [],
        ));

        $this->assertSame(400, $response->statusCode);
    }

    public function testPostCallReturns500ForHandlerException(): void
    {
        $this->client->register(new Task(
            id: 'failing-task',
            triggers: [new Event(name: 'test/event')],
            handler: function (Context $ctx): never {
                throw new \RuntimeException('Something broke');
            },
        ));

        $response = $this->handler->handle(new Request(
            method: 'POST',
            headers: [],
            body: json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'test/event', 'data' => []],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
            query: ['fnId' => 'test-app-failing-task', 'stepId' => 'step'],
        ));

        $this->assertSame(500, $response->statusCode);
        $this->assertSame('Something broke', $response->body['error']);
    }

    public function testSignatureVerificationRejectsInvalidSignature(): void
    {
        $client = new Client(
            appName: 'secure-app',
            serveUrl: 'https://example.com/api/flowline',
            eventKey: 'test-event-key',
            signingKey: 'secret-key',
        );

        $response = $client->handler()->handle(new Request(
            method: 'GET',
            headers: [],
            body: '',
        ));

        $this->assertSame(401, $response->statusCode);
    }

    public function testSignatureVerificationAcceptsValidSignature(): void
    {
        $client = new Client(
            appName: 'secure-app',
            serveUrl: 'https://example.com/api/flowline',
            eventKey: 'test-event-key',
            signingKey: 'secret-key',
        );

        $body = json_encode(['test' => 'data']);
        $signature = hash_hmac('sha256', $body, 'secret-key');

        $response = $client->handler()->handle(new Request(
            method: 'GET',
            headers: ['x-flowline-signature' => $signature],
            body: $body,
        ));

        $this->assertSame(200, $response->statusCode);
    }

    public function testMethodNotAllowed(): void
    {
        $response = $this->handler->handle(new Request(
            method: 'DELETE',
            headers: [],
            body: '',
        ));

        $this->assertSame(405, $response->statusCode);
    }

    public function testSleepStepOperation(): void
    {
        $this->client->register(new Task(
            id: 'delayed-task',
            triggers: [new Event(name: 'test/event')],
            handler: function (Context $ctx): void {
                $ctx->step->sleep('wait', '5m');
            },
        ));

        $response = $this->handler->handle(new Request(
            method: 'POST',
            headers: [],
            body: json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'test/event', 'data' => []],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
            query: ['fnId' => 'test-app-delayed-task', 'stepId' => 'step'],
        ));

        $this->assertSame(206, $response->statusCode);
        $this->assertSame('Sleep', $response->body[0]['op']);
        $this->assertSame(['duration' => '5m'], $response->body[0]['opts']);
    }
}
