<?php

declare(strict_types=1);

namespace Flowline;

use Flowline\Http\Handler;
use Flowline\Protocol\RegistrationPayload;

/**
 * Main SDK entry point. Registers tasks and provides the HTTP handler.
 *
 * Usage:
 *
 *   $client = new Client(
 *       appName: 'my-app',
 *       serveUrl: 'https://app.example.com/api/flowline',
 *       eventKey: 'my-event-key',
 *   );
 *
 *   $client->register(
 *       new Task(
 *           id: 'process-order',
 *           triggers: [new Event(name: 'order/created')],
 *           handler: function (Context $ctx) {
 *               $order = $ctx->step->run('fetch', fn () => Order::find($ctx->event->data['id']));
 *               $ctx->step->sleep('delay', '5m');
 *               $ctx->step->run('notify', fn () => Mail::send(...));
 *           },
 *       )
 *   );
 *
 *   $handler = $client->handler();
 */
final class Client
{
    private const SDK_VERSION = '0.1.0';

    /** @var array<string, Task> */
    private array $tasks = [];

    /**
     * @param string $appName Application identifier used as task ID prefix
     * @param string $serveUrl The URL where this SDK is reachable by the platform
     * @param string $eventKey Ingest key for sending events (unique per environment and application)
     * @param string|null $signingKey Platform signing key for request verification (null = dev mode)
     * @param string $platformUrl Platform API base URL
     */
    public function __construct(
        public readonly string $appName,
        public readonly string $serveUrl,
        public readonly string $eventKey,
        public readonly ?string $signingKey = null,
        public readonly string $platformUrl = 'https://app.flowline.dev',
    ) {}

    public function register(Task $task): void
    {
        $compositeId = $this->compositeId($task->id);
        $this->tasks[$compositeId] = $task;
    }

    public function getTask(string $compositeId): ?Task
    {
        return $this->tasks[$compositeId] ?? null;
    }

    /**
     * @return array<string, Task>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    public function handler(): Handler
    {
        return new Handler($this);
    }

    public function sdkHeader(): string
    {
        return 'flowline-php:' . self::SDK_VERSION;
    }

    public function buildRegistrationPayload(): RegistrationPayload
    {
        $functions = [];

        foreach ($this->tasks as $compositeId => $task) {
            $triggers = array_map(
                fn (Event $e): array => $e->toArray(),
                $task->triggers,
            );

            $stepUrl = $this->serveUrl . (str_contains($this->serveUrl, '?') ? '&' : '?') . http_build_query([
                'fnId' => $compositeId,
                'stepId' => 'step',
            ]);

            $functions[] = [
                'id' => $compositeId,
                'name' => $task->name ?? $task->id,
                'triggers' => $triggers,
                'steps' => [
                    'step' => [
                        'id' => 'step',
                        'name' => 'step',
                        'runtime' => [
                            'type' => 'http',
                            'url' => $stepUrl,
                        ],
                        'retries' => $task->retries,
                    ],
                ],
            ];
        }

        return new RegistrationPayload(
            url: $this->serveUrl,
            appName: $this->appName,
            sdk: $this->sdkHeader(),
            functions: $functions,
        );
    }

    /**
     * Send an event to the platform. The platform routes the event
     * to all registered tasks whose triggers match the event name.
     *
     * @return array Platform response body
     */
    public function sendEvent(Event $event): array
    {
        $url = rtrim($this->platformUrl, '/') . '/e/' . $this->eventKey;

        $payload = json_encode($event->toArray());

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Flowline-SDK: ' . $this->sdkHeader(),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode(is_string($response) ? $response : '', true) ?? [];
    }

    private function compositeId(string $taskId): string
    {
        return $this->appName . '-' . $taskId;
    }
}
