<?php

declare(strict_types=1);

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

require_once __DIR__ . '/../../vendor/autoload.php';

interface JarvisSignalBus
{
    /**
     * Repeated publication with the same ID must not create duplicate signals.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(string $id, string $topic, array $payload): void;
}

interface JarvisKnowledgeStore
{
    /** Repeated writes to a key replace its value. */
    public function put(string $key, string $value): void;

    public function get(string $key): ?string;
}

interface JarvisHeartbeatStore
{
    public function lastSeen(string $agent): ?DateTimeImmutable;
}

interface JarvisAgentFactory
{
    /** Return a newly configured agent with the supplied conversation identity. */
    public function make(string $role, string $threadId): AgentInterface;
}

final class JarvisResources extends WorkflowResources
{
    public function __construct(
        public readonly JarvisSignalBus $signals,
        public readonly JarvisKnowledgeStore $knowledge,
        public readonly JarvisHeartbeatStore $heartbeats,
        public readonly JarvisAgentFactory $agents,
    ) {
        parent::__construct();
    }
}

abstract class JarvisAgentNode extends Node
{
    protected function ask(
        JarvisAgentFactory $agents,
        string $role,
        string $threadId,
        string $prompt,
    ): string {
        return $this->memoize(
            "agent.{$role}",
            function () use ($agents, $role, $threadId, $prompt): string {
                $answer = $agents->make($role, $threadId)
                    ->chat(new UserMessage($prompt))
                    ->getMessage()
                    ?->getContent();

                if ($answer === null || \trim($answer) === '') {
                    throw new RuntimeException("The {$role} agent returned no text.");
                }

                return \trim($answer);
            },
        );
    }

    protected function stateString(WorkflowState $state, string $key): string
    {
        $value = $state->get($key);
        if (!\is_string($value)) {
            throw new RuntimeException("The workflow state is missing the '{$key}' value.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function publish(
        JarvisResources $resources,
        WorkflowState $state,
        string $topic,
        array $payload,
    ): void {
        $workflowId = $state->getWorkflowId();
        if ($workflowId === null) {
            throw new RuntimeException('The workflow ID is not available.');
        }

        $resources->signals->publish("{$workflowId}/{$topic}", $topic, $payload);
    }
}

final class BriefingStarted implements Event
{
    public function __construct(public readonly string $date)
    {
    }
}

final class ResearchCompleted implements Event
{
}

final class OperationsCompleted implements Event
{
}

final class BriefingComposed implements Event
{
}

final class BriefingResearchAgent extends JarvisAgentNode
{
    public function __invoke(
        BriefingStarted $event,
        WorkflowState $state,
        JarvisResources $resources,
    ): ResearchCompleted {
        $research = $this->ask(
            $resources->agents,
            'research',
            "jarvis:research:{$event->date}",
            "Prepare concise, sourced research highlights for the {$event->date} daily briefing.",
        );

        $state->set('research', $research);
        $this->publish($resources, $state, 'research.completed', ['date' => $event->date]);
        $resources->knowledge->put("briefing/{$event->date}/research", $research);

        return new ResearchCompleted();
    }
}

final class BriefingOperationsAgent extends JarvisAgentNode
{
    public function __invoke(
        ResearchCompleted $event,
        WorkflowState $state,
        JarvisResources $resources,
    ): OperationsCompleted {
        $date = $this->stateString($state, 'date');
        $research = $resources->knowledge->get("briefing/{$date}/research");
        if ($research === null) {
            throw new RuntimeException("Research notes for {$date} are missing from the knowledge store.");
        }

        $operations = $this->ask(
            $resources->agents,
            'operations',
            "jarvis:operations:{$date}",
            "Summarize today's operational status and risks in light of these research notes:\n\n{$research}",
        );

        $state->set('operations', $operations);
        $this->publish($resources, $state, 'operations.completed', [
            'date' => $date,
            'research_available' => $state->has('research'),
        ]);
        $resources->knowledge->put("briefing/{$date}/operations", $operations);

        return new OperationsCompleted();
    }
}

final class BriefingComposer extends JarvisAgentNode
{
    public function __invoke(
        OperationsCompleted $event,
        WorkflowState $state,
        JarvisResources $resources,
    ): BriefingComposed {
        $date = $this->stateString($state, 'date');
        $research = $resources->knowledge->get("briefing/{$date}/research");
        $operations = $resources->knowledge->get("briefing/{$date}/operations");
        if ($research === null || $operations === null) {
            throw new RuntimeException("The briefing inputs for {$date} are missing from the knowledge store.");
        }

        $briefing = $this->ask(
            $resources->agents,
            'briefing',
            "jarvis:briefing:{$date}",
            "Write a concise daily briefing for {$date} using these research and operations notes:\n\n"
                . "Research:\n{$research}\n\n"
                . "Operations:\n{$operations}",
        );

        $state->set('briefing', $briefing);
        $resources->knowledge->put("briefing/{$date}/final", $briefing);
        $this->publish($resources, $state, 'briefing.ready', ['date' => $date]);

        return new BriefingComposed();
    }
}

final class FinishBriefing extends Node
{
    public function __invoke(BriefingComposed $event, WorkflowState $state): StopEvent
    {
        return new StopEvent();
    }
}

final class DailyBriefingWorkflow extends Workflow
{
    public function __construct(string $date)
    {
        parent::__construct("jarvis-briefing-{$date}", new WorkflowState(['date' => $date]));
        $this->setStartEvent(new BriefingStarted($date));
        $this->setLeaseTimeout(900);
        $this->setMaxSteps(4);
        $this->addNodes([
            new BriefingResearchAgent(),
            new BriefingOperationsAgent(),
            new BriefingComposer(),
            new FinishBriefing(),
        ]);
    }
}

final class WatchdogRequest implements Event
{
    /**
     * @param list<string> $agents
     */
    public function __construct(
        public readonly array $agents,
        public readonly DateTimeImmutable $checkedAt,
        public readonly int $staleAfterSeconds,
    ) {
        if ($staleAfterSeconds < 1) {
            throw new InvalidArgumentException('The stale heartbeat threshold must be positive.');
        }
    }
}

final class CheckAgentHeartbeats extends Node
{
    public function __invoke(
        WatchdogRequest $event,
        WorkflowState $state,
        JarvisResources $resources,
    ): StopEvent {
        foreach ($event->agents as $agent) {
            $lastSeen = $resources->heartbeats->lastSeen($agent);
            if (
                $lastSeen instanceof DateTimeImmutable
                && $event->checkedAt->getTimestamp() - $lastSeen->getTimestamp() <= $event->staleAfterSeconds
            ) {
                continue;
            }

            $lastSeenId = $lastSeen?->format('Ymd\THis.uP') ?? 'missing';
            $key = \sprintf('watchdog/%s/%s', $agent, $lastSeenId);
            $resources->knowledge->put(
                $key,
                $lastSeen instanceof DateTimeImmutable ? 'Agent heartbeat is stale.' : 'Agent heartbeat is missing.',
            );
            $resources->signals->publish($key, 'agent.heartbeat.stale', [
                'agent' => $agent,
                'last_seen' => $lastSeen?->format(DATE_ATOM),
                'checked_at' => $event->checkedAt->format(DATE_ATOM),
            ]);
        }

        return new StopEvent();
    }
}

final class WatchdogWorkflow extends Workflow
{
    /**
     * @param list<string> $agents
     */
    public function __construct(
        string $workflowId,
        array $agents,
        DateTimeImmutable $checkedAt,
        int $staleAfterSeconds = 300,
    ) {
        parent::__construct($workflowId);
        $this->setStartEvent(new WatchdogRequest($agents, $checkedAt, $staleAfterSeconds));
        $this->setLeaseTimeout(60);
        $this->setMaxSteps(1);
        $this->addNode(new CheckAgentHeartbeats());
    }
}
