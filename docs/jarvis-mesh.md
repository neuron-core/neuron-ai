# Jarvis mesh prototype

This example composes role-specific Neuron agents in a workflow:

Load `examples/agent/jarvis-mesh.php` from the application bootstrap.

`DailyBriefingWorkflow` routes research, operations, and composition through
typed workflow events. The agents, signal bus, knowledge store, and heartbeat
store are supplied as `JarvisResources`, rebuilt for each execution segment.
Implement `JarvisAgentFactory` by returning a fresh configured `AgentInterface`
for each role and thread; provide role-appropriate instructions, tools, provider,
and message store there.

Configure the workflow from the application after providing its adapters:

```php
$resourcesFactory = static fn (): JarvisResources => new JarvisResources(
    $signalBus,
    $knowledgeStore,
    $heartbeatStore,
    $agentFactory,
);

$date = (new DateTimeImmutable())->format('Y-m-d');
$workflow = (new DailyBriefingWorkflow($date))
    ->setPersistence($workflowPersistence)
    ->setResources($resourcesFactory);

$state = $workflow->run();
$briefing = $state->get('briefing');
if (!is_string($briefing)) {
    throw new RuntimeException('The workflow returned no briefing.');
}
echo $briefing;
```

The example assumes the application has configured `$signalBus`,
`$knowledgeStore`, `$heartbeatStore`, `$agentFactory`, and
`$workflowPersistence`. Use shared, durable implementations for the bus and
knowledge store, and a multi-process-safe workflow persistence backend when
workers may overlap. Their contracts require signal publication to be
idempotent by ID and knowledge writes to be idempotent by key. Workflow memo
records avoid repeating completed agent calls after replay, but cannot make an
external side effect exactly-once.

`WatchdogWorkflow` is a bounded, single-pass sweep intended to be invoked by an
application scheduler. It publishes an idempotently keyed alert when a
monitored agent's heartbeat is older than the requested threshold. The
application owns scheduling, heartbeat production, alert delivery, and
monitoring policy.

For example, the scheduler can run a distinct watchdog execution per sweep:

```php
$checkedAt = new DateTimeImmutable();
$watchdog = (new WatchdogWorkflow(
    'jarvis-watchdog-' . $checkedAt->format('YmdHis'),
    ['research', 'operations', 'briefing'],
    $checkedAt,
))
    ->setPersistence($workflowPersistence)
    ->setResources($resourcesFactory);

$watchdog->run();
```

The shared bus is an application integration boundary, not
`Workflow::signal()`: workflow signals answer the current matching wait and
are not a broadcast or a queue. Keep transport and scheduling outside workflow
nodes; let the workflow graph own business sequencing and state.
