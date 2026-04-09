<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use ReflectionClass;

use function array_is_list;

/**
 * Event that triggers parallel branch execution.
 *
 * When a node's __invoke() returns a ParallelEvent, the executor runs all branches
 * (sequentially with SequentialExecutor, concurrently with AsyncExecutor). After all
 * branches complete, the workflow continues in one of two ways:
 *
 *  - If the returning node extends ParallelNode (i.e. it has a merge() method), that
 *    method is called and its returned event is routed to the next node normally.
 *
 *  - Otherwise (JoinNode pattern), the ParallelEvent instance itself is routed through
 *    the event→node map. Register a separate join node that handles the specific
 *    ParallelEvent subclass to continue the workflow after the parallel section.
 *
 * Branch IDs:
 *  - Associative array keys are used as-is as branch IDs.
 *  - Sequential (integer-indexed) arrays auto-derive IDs from the event class short name
 *    (e.g. new ExtractTextEvent() → branch ID "ExtractTextEvent").
 *
 * Branch state changes are stored under "branches.{branchId}.*" in WorkflowState.
 *
 * Example — JoinNode pattern (no merge method needed):
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
 *   class CompileResults extends Node {
 *       public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
 *       {
 *           $state->set('result', [
 *               'text'   => $state->get('branches.text.result'),
 *               'images' => $state->get('branches.images.result'),
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
     * @param array<string, Event>|array<int, Event> $branches
     *   Named branches (string keys) or unnamed branches (integer keys, IDs are
     *   auto-derived from the short class name of each event).
     */
    public function __construct(array $branches)
    {
        if (array_is_list($branches)) {
            $named = [];
            foreach ($branches as $branchEvent) {
                $id = (new ReflectionClass($branchEvent))->getShortName();
                $named[$id] = $branchEvent;
            }
            $this->branches = $named;
        } else {
            $this->branches = $branches;
        }
    }
}
