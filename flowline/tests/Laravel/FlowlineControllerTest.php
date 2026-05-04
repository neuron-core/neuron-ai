<?php

declare(strict_types=1);

namespace Flowline\Tests\Laravel;

use Flowline\Client;
use Flowline\Context;
use Flowline\Event;
use Flowline\Laravel\FlowlineController;
use Flowline\Task;
use Illuminate\Http\Request as LaravelRequest;
use PHPUnit\Framework\TestCase;

final class FlowlineControllerTest extends TestCase
{
    private Client $client;
    private FlowlineController $controller;

    protected function setUp(): void
    {
        $this->client = new Client(
            appName: 'test-app',
            serveUrl: 'https://example.com/api/flowline',
            eventKey: 'test-event-key',
        );
        $this->controller = new FlowlineController($this->client);
    }

    public function testGetIntrospection(): void
    {
        $this->client->register(new Task(
            id: 'my-task',
            triggers: [new Event(name: 'test/event')],
            handler: fn () => null,
        ));

        $request = LaravelRequest::create('/api/flowline', 'GET');
        $response = ($this->controller)($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('cloud', $response->getData()->mode);
        $this->assertSame(1, $response->getData()->functionCount);
    }

    public function testGetIntrospectionIncludesSdkHeader(): void
    {
        $request = LaravelRequest::create('/api/flowline', 'GET');
        $response = ($this->controller)($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('flowline-php:', $response->headers->get('X-Flowline-SDK'));
    }

    public function testPutSync(): void
    {
        $this->client->register(new Task(
            id: 'my-task',
            triggers: [new Event(name: 'test/event')],
            handler: fn () => null,
        ));

        $request = LaravelRequest::create('/api/flowline', 'PUT');
        $response = ($this->controller)($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData()->modified);
        $this->assertObjectHasProperty('payload', $response->getData());
        $this->assertCount(1, $response->getData()->payload->functions);
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

        $request = LaravelRequest::create(
            '/api/flowline?fnId=test-app-process-order&stepId=step',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'order/created', 'data' => []],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
        );

        $response = ($this->controller)($request);

        $this->assertSame(206, $response->getStatusCode());
        $body = $response->getData();
        $this->assertCount(1, $body);
        $this->assertSame('StepRun', $body[0]->op);
        $this->assertSame('fetch-order', $body[0]->displayName);
        $this->assertSame(['id' => 42], (array) $body[0]->data);
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

        $request = LaravelRequest::create(
            '/api/flowline?fnId=test-app-process-order&stepId=step',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'order/created', 'data' => []],
                'steps' => [sha1('step-1') => ['data' => 'memoized-result']],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
        );

        $response = ($this->controller)($request);

        $this->assertSame(206, $response->getStatusCode());
        $body = $response->getData();
        $this->assertSame('step-2', $body[0]->displayName);
        $this->assertSame('also-executed', $body[0]->data);
        $this->assertSame(1, $callCount);
    }

    public function testPostCallReturns200WhenFunctionCompletes(): void
    {
        $this->client->register(new Task(
            id: 'simple-task',
            triggers: [new Event(name: 'test/event')],
            handler: fn (Context $ctx): string => 'done',
        ));

        $request = LaravelRequest::create(
            '/api/flowline?fnId=test-app-simple-task&stepId=step',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'test/event', 'data' => []],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
        );

        $response = ($this->controller)($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('done', $response->getData());
    }

    public function testPostCallReturns404ForUnknownFunction(): void
    {
        $request = LaravelRequest::create(
            '/api/flowline?fnId=nonexistent&stepId=step',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        $response = ($this->controller)($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPostCallReturns400ForMissingFnId(): void
    {
        $request = LaravelRequest::create(
            '/api/flowline',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{}',
        );

        $response = ($this->controller)($request);

        $this->assertSame(400, $response->getStatusCode());
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

        $request = LaravelRequest::create(
            '/api/flowline?fnId=test-app-failing-task&stepId=step',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'test/event', 'data' => []],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
        );

        $response = ($this->controller)($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Something broke', $response->getData()->error);
    }

    public function testSignatureVerificationRejectsMissingSignature(): void
    {
        $client = new Client(
            appName: 'secure-app',
            serveUrl: 'https://example.com/api/flowline',
            eventKey: 'test-event-key',
            signingKey: 'secret-key',
        );
        $controller = new FlowlineController($client);

        $request = LaravelRequest::create('/api/flowline', 'GET');
        $response = ($controller)($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testSignatureVerificationAcceptsValidSignature(): void
    {
        $client = new Client(
            appName: 'secure-app',
            serveUrl: 'https://example.com/api/flowline',
            eventKey: 'test-event-key',
            signingKey: 'secret-key',
        );
        $controller = new FlowlineController($client);

        $body = '';
        $signature = hash_hmac('sha256', $body, 'secret-key');

        $request = LaravelRequest::create(
            '/api/flowline',
            'GET',
            [],
            [],
            [],
            ['HTTP_X_FLOWLINE_SIGNATURE' => $signature],
            $body,
        );

        $response = ($controller)($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMethodNotAllowed(): void
    {
        $request = LaravelRequest::create('/api/flowline', 'DELETE');
        $response = ($this->controller)($request);

        $this->assertSame(405, $response->getStatusCode());
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

        $request = LaravelRequest::create(
            '/api/flowline?fnId=test-app-delayed-task&stepId=step',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'event' => ['id' => 'evt-1', 'name' => 'test/event', 'data' => []],
                'ctx' => ['run_id' => 'run-1', 'attempt' => 0],
            ]),
        );

        $response = ($this->controller)($request);

        $this->assertSame(206, $response->getStatusCode());
        $body = $response->getData();
        $this->assertSame('Sleep', $body[0]->op);
        $this->assertSame('5m', $body[0]->opts->duration);
    }
}
