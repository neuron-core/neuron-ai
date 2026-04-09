<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Node;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\ParallelEvent;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;

/**
 * Convenience base for nodes that both fork parallel branches and merge their results.
 *
 * Implement __invoke() to return a ParallelEvent carrying the branch-starting events,
 * and merge() to combine the branch results into the next workflow event. The executor
 * handles how branches are actually run (sequentially or concurrently).
 *
 * For separation of concerns, prefer the JoinNode pattern instead: return a ParallelEvent
 * subclass from any plain Node and register a separate join Node that handles that subclass.
 * See ParallelEvent for full documentation and examples.
 *
 * Usage:
 *   class DocumentAnalysisNode extends ParallelNode {
 *       public function __invoke(StartEvent $event, WorkflowState $state): ParallelEvent
 *       {
 *           return new ParallelEvent([
 *               'text'   => new ExtractTextEvent(),
 *               'images' => new AnalyzeImagesEvent(),
 *           ]);
 *       }
 *
 *       public function merge(array $results, WorkflowState $state): Event
 *       {
 *           $state->set('analysis', [
 *               'text'   => $state->get('branches.text.extractedText'),
 *               'images' => $state->get('branches.images.imageAnalysis'),
 *           ]);
 *           return new MergeResultsEvent();
 *       }
 *   }
 */
abstract class ParallelNode extends Node
{
    /**
     * Merge results from all completed branches into the next workflow event.
     *
     * Called after all branches complete. Branch state changes are available
     * under "branches.{branchId}.*" keys in the workflow state.
     *
     * @param array<string, Event> $results Branch identifier → final event from each branch
     */
    abstract public function merge(array $results, WorkflowState $state): Event;
}
