<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Events;

use ReflectionClass;

use function array_is_list;

/**
 * Event that triggers parallel branch execution.
 *
 * When a node's __invoke() returns a ParallelEvent subclass, the executor runs all
 * branches (sequentially by default, concurrently with AsyncExecutor). After all
 * branches complete, branch state changes are stored under "branches.{branchId}.*"
 * in WorkflowState, and the ParallelEvent instance is routed through the event→node
 * map to a join node.
 *
 * Pattern:
 *  1. Extend ParallelEvent for your specific parallel operation.
 *  2. Return it from a fork node's __invoke(), passing the branch-starting events.
 *  3. Register a join node whose __invoke() accepts your ParallelEvent subclass.
 *     Read branch results from state under "branches.{branchId}.*".
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
 *   class CompileResults extends Node {
 *       public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
 *       {
 *           $state->set('result', [
 *               'text'   => $state->get('branches.text.extractedText'),
 *               'images' => $state->get('branches.images.imageAnalysis'),
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
