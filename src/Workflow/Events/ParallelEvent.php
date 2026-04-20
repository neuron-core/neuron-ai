<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use function array_is_list;

/**
 * Event that triggers parallel branch execution.
 *
 * When a node's __invoke() returns a ParallelEvent subclass, the executor runs all
 * branches (sequentially by default, concurrently with AsyncExecutor). After all
 * branches complete, each branch's StopEvent::getResult() is stored in
 * {@see $branchResults}, and the ParallelEvent instance is routed through the
 * event→node map to a join node.
 *
 * Pattern:
 *  1. Extend ParallelEvent for your specific parallel operation.
 *  2. Return it from a fork node's __invoke(), passing the branch-starting events.
 *  3. Register a join node whose __invoke() accepts your ParallelEvent subclass.
 *     Read branch results from {@see $branchResults}.
 *
 * Branch IDs:
 *  - Associative array keys are used as-is as branch IDs.
 *  - Sequential (integer-indexed) arrays auto-derive IDs from the event class short name
 *    (e.g. new ExtractTextEvent() → branch ID "ExtractTextEvent").
 *
 * Example:
 *
 *   class DocumentParallelEvent extends ParallelEvent {}
 *
 *   class AnalyzeDocument extends Node {
 *       public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
 *       {
 *           return new DocumentParallelEvent([
 *               'text'   => new ExtractTextEvent(),
 *               'images' => new AnalyzeImagesEvent(),
 *           ]);
 *       }
 *   }
 *
 *   class ExtractText extends Node {
 *       public function __invoke(ExtractTextEvent $event, WorkflowState $state): StopEvent
 *       {
 *           return new StopEvent(result: $extractedText);
 *       }
 *   }
 *
 *   class CompileResults extends Node {
 *       public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
 *       {
 *           $state->set('result', [
 *               'text'   => $event->branchResults['text'],
 *               'images' => $event->branchResults['images'],
 *           ]);
 *           return new StopEvent();
 *       }
 *   }
 */
class ParallelEvent implements Event
{
    /** @var array<string, Event> */
    public readonly array $branches;

    /**
     * Branch results, keyed by branch ID.
     *
     * Populated by the executor after all branches complete. Each value is
     * whatever the branch's terminal node returned via `StopEvent::getResult()`.
     *
     * @var array<string, mixed>
     */
    protected array $results = [];

    /**
     * @param array<string, Event>|array<int, Event> $branches
     *   Named branches (string keys) or unnamed branches (integer keys, IDs are
     *   auto-derived from the short class name of each event).
     */
    public function __construct(array $branches)
    {
        if (array_is_list($branches)) {
            $named = [];
            foreach ($branches as $branchEvent) {
                $named[$branchEvent::class] = $branchEvent;
            }
            $this->branches = $named;
        } else {
            $this->branches = $branches;
        }
    }

    public function setResult(string $branch, mixed $result): self
    {
        $this->results[$branch] = $result;
        return $this;
    }

    public function getResult(string $branch): mixed
    {
        return $this->results[$branch];
    }

    public function getAllResults(): array
    {
        return $this->results;
    }

    public function hasResult(string $branch): bool
    {
        return isset($this->results[$branch]);
    }
}
