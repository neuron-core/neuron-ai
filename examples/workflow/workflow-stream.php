<?php

declare(strict_types=1);

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;

require_once __DIR__ . '/../../vendor/autoload.php';

final class ProcessingStarted implements Event
{
}

final class ProgressUpdate implements Event
{
    public function __construct(public readonly string $message)
    {
    }
}

final class ProcessingFinished implements Event
{
    public function __construct(public readonly string $message)
    {
    }
}

final class StartProcessing extends Node
{
    public function __invoke(StartEvent $event, WorkflowState $state): ProcessingStarted
    {
        return new ProcessingStarted();
    }
}

final class StreamProgress extends Node
{
    public function __invoke(ProcessingStarted $event, WorkflowState $state): Generator
    {
        yield new ProgressUpdate('Loading input');
        yield new ProgressUpdate('Processing input');

        return new ProcessingFinished('Processing complete');
    }
}

final class FinishProcessing extends Node
{
    public function __invoke(ProcessingFinished $event, WorkflowState $state): StopEvent
    {
        $state->set('result', $event->message);

        return new StopEvent();
    }
}

$workflow = Workflow::make()
    ->addNodes([
        new StartProcessing(),
        new StreamProgress(),
        new FinishProcessing(),
    ]);

$events = $workflow->events();

foreach ($events as $event) {
    if ($event instanceof ProgressUpdate) {
        echo '- ' . $event->message . \PHP_EOL;
    }
}

$completed = $events->getReturn();

echo $completed->get('result') . \PHP_EOL;
