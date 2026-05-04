<?php

declare(strict_types=1);

namespace Flowline\Tests;

use Flowline\Client;
use Flowline\Event;
use Flowline\Task;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = new Client(
            appName: 'test-app',
            serveUrl: 'https://example.com/api/flowline',
            eventKey: 'test-event-key',
        );
    }

    public function testRegisterTask(): void
    {
        $task = new Task(
            id: 'process-order',
            triggers: [new Event(name: 'order/created')],
            handler: fn () => null,
        );

        $this->client->register($task);

        $this->assertNotNull($this->client->getTask('test-app-process-order'));
        $this->assertNull($this->client->getTask('nonexistent'));
    }

    public function testGetTasks(): void
    {
        $this->client->register(new Task(
            id: 'task-a',
            triggers: [new Event(name: 'a')],
            handler: fn () => null,
        ));
        $this->client->register(new Task(
            id: 'task-b',
            triggers: [new Event(name: 'b')],
            handler: fn () => null,
        ));

        $tasks = $this->client->getTasks();

        $this->assertCount(2, $tasks);
        $this->assertArrayHasKey('test-app-task-a', $tasks);
        $this->assertArrayHasKey('test-app-task-b', $tasks);
    }

    public function testSdkHeader(): void
    {
        $this->assertStringStartsWith('flowline-php:', $this->client->sdkHeader());
    }

    public function testBuildRegistrationPayload(): void
    {
        $this->client->register(new Task(
            id: 'process-order',
            triggers: [new Event(name: 'order/created')],
            handler: fn () => null,
            name: 'Process Order',
        ));

        $payload = $this->client->buildRegistrationPayload();
        $array = $payload->toArray();

        $this->assertSame('https://example.com/api/flowline', $array['url']);
        $this->assertSame('test-app', $array['appName']);
        $this->assertSame('0.1', $array['v']);
        $this->assertCount(1, $array['functions']);

        $fn = $array['functions'][0];
        $this->assertSame('test-app-process-order', $fn['id']);
        $this->assertSame('Process Order', $fn['name']);
        $this->assertSame([['name' => 'order/created']], $fn['triggers']);
        $this->assertArrayHasKey('step', $fn['steps']);
        $this->assertSame('http', $fn['steps']['step']['runtime']['type']);
        $this->assertStringContainsString('fnId=test-app-process-order', $fn['steps']['step']['runtime']['url']);
    }

    public function testHandlerReturnsHttpHandler(): void
    {
        $handler = $this->client->handler();

        $this->assertInstanceOf(\Flowline\Http\Handler::class, $handler);
    }
}
