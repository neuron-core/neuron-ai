# Observability Module

EventBus architecture for monitoring. All components emit events.

## Core

| File | Purpose |
|------|---------|
| `EventBus.php` | Static event bus, `emit()` and `observe()` |
| `ObserverInterface.php` | Contract: `update(source, event, data)` |

## Usage

```php
// Register observer
EventBus::observe(new LogObserver($logger));

// Emit events (internal)
EventBus::emit('custom-event', $this, $data);
```

## Built-in Observers

### LogObserver

PSR-3 logger integration:
```php
new LogObserver($psrLogger)
```

### InspectorObserver

Inspector APM integration. Tracks framework events as segments.

**Extending for custom events**:
```php
class CustomObserver extends InspectorObserver
{
    protected array $methodsMap = [
        ...parent::$methodsMap,
        'custom-event' => 'handleCustomEvent',
    ];

    public function handleCustomEvent(object $source, string $event, mixed $data): void
    {
        // Track custom event
    }
}
```

## Event Handlers

Traits for emitting events:

| Trait | Events |
|-------|--------|
| `HandleWorkflowEvents` | Workflow lifecycle |
| `HandleToolEvents` | Tool execution |
| `HandleInferenceEvents` | AI inference |
| `HandleRagEvents` | RAG retrieval |
| `HandleStructuredEvents` | Structured output |

## Registration via Workflow

```php
$workflow->observe(new InspectorObserver($inspector));
```

## Events (`Events/`)

Event data containers for different lifecycle points.

## Dependencies

None. Self-contained.
