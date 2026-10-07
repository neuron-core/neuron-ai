# Observability: Neuron events in Laravel

Laravel's dispatcher is not PSR-14 (`Illuminate\Events\Dispatcher` implements only Laravel's own contract). A small bridge forwards Neuron's events; `NeuronServiceProvider::register()` sets it on every workflow and agent the container builds (the callback fires for Agent subclasses, and `for()` copies keep it):

```php
// app/Providers/NeuronServiceProvider.php, in register()
use App\Neuron\LaravelEventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use NeuronAI\Workflow\Workflow;

$this->app->singleton(LaravelEventDispatcher::class);
$this->app->afterResolving(Workflow::class, function (Workflow $workflow, Application $app): void {
    $workflow->setEventDispatcher($app->make(LaravelEventDispatcher::class));
});
```

```php
namespace App\Neuron;

use Illuminate\Contracts\Container\Container;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

class LaravelEventDispatcher implements EventDispatcherInterface
{
    public function __construct(protected Container $container)
    {
    }

    public function dispatch(object $event): object
    {
        // A throwing listener on an event a node emits would fail the run.
        try {
            // Resolved per event: Event::fake() swaps the dispatcher after this bridge is built.
            $this->container->make('events')->dispatch($event);
        } catch (Throwable $e) {
            report($e);
        }

        return $event;
    }
}
```

- Listen to concrete classes (`NeuronAI\Workflow\Observability\WorkflowEnd`, `NeuronAI\Agent\Observability\InferenceStop`, …) or the wildcard `Event::listen('NeuronAI\*', fn (string $name, array $payload) => ...)`. `Event::listen(ObservabilityEvent::class, …)` never fires: Laravel matches exact classes and interfaces, not parent classes.
- Never queue listeners on live Neuron events: they reference the running agent and its state, and do not serialize (a captured `WorkflowEnd` failed with "Serialization of 'Pdo\Sqlite' is not allowed"). Listen synchronously and dispatch your own job with scalar data.
- Log every event through a Laravel channel with Neuron's own matching: `$agent->subscribe(ObservabilityEvent::class, new LogListener(Log::channel('neuron')))` on the bound copy. Redaction and tracing: **neuron-monitoring**.

The `neuron` channel is the app's own, in `config/logging.php` (or pass an existing channel's name). An undefined channel does not fail: Laravel logs "Log [neuron] is not defined" and writes to the emergency logger instead.

```php
// config/logging.php, in 'channels'
'neuron' => [
    'driver' => 'daily',
    'path' => storage_path('logs/neuron.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'max_files' => 14,
],
```

What ran: a concrete `Event::listen(WorkflowEnd::class, …)` received the event with the thread ID in `$event->state->getWorkflowId()`; the `'NeuronAI\*'` wildcard received every event; a listener on `ObservabilityEvent::class` received nothing. A listener on `InferenceStop` that threw failed the request with a 500 without the bridge's `try`/`catch`, and with it the turn completed and the exception was reported once. `Event::fake([WorkflowEnd::class])` with `Event::assertDispatched(WorkflowEnd::class, fn (WorkflowEnd $event): bool => …)` works through the bridge whether the test fakes events before or after the agent is resolved; a bridge that kept the dispatcher from its constructor missed the event when `fakeSupportAgent()` ran first. `LogListener(Log::channel('neuron'))` writes one record per event to `storage/logs/neuron-<date>.log`, named after it (`workflow-start`, `inference-start`, …) with the event's data as context.
