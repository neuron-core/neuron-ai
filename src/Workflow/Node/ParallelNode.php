<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Node;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

/**
 * Abstract node that executes multiple branches concurrently.
 *
 * Usage:
 *   class DocumentAnalysisNode extends ParallelNode {
 *       // Specify which event this node handles
 *       public function __invoke(StartEvent $event, WorkflowState $state): Event {
 *           return $this->execute($event, $state);
 *       }
 *
 *       protected function branches(StartEvent $event, WorkflowState $state): array {
 *           return [
 *               'text' => new ExtractTextEvent($event->document),
 *               'images' => new AnalyzeImagesEvent($event->document),
 *           ];
 *       }
 *
 *       protected function merge(array $results, WorkflowState $state): Event {
 *           $analysis = [
 *               'text' => $state->get('branches.text.extractedText'),
 *               'images' => $state->get('branches.images.imageAnalysis'),
 *           ];
 *           $state->set('analysis', $analysis);
 *           return new MergeResultsEvent();
 *       }
 *   }
 */
abstract class ParallelNode extends Node
{
    /**
     * Define parallel branches to execute.
     * Each key-value pair defines a branch identified by key.
     *
     * @param Event $event The event that triggered this node
     * @param WorkflowState $state The workflow state
     * @return array<string, Event> Branch identifier → starting event
     */
    abstract public function branches(Event $event, WorkflowState $state): array;

    /**
     * Merge results from all completed branches.
     *
     * This method is called after all branches have completed (or after handling errors).
     * Branch state changes are already merged into the main state under "branches.{branchId}.*" keys.
     *
     * @param array<string, Event> $results Branch identifier → final event from each branch
     * @param WorkflowState $state The workflow state (with branch changes merged)
     * @return Event The next event to continue the workflow
     */
    abstract public function merge(array $results, WorkflowState $state): Event;

    /**
     * Execute the parallel node - called by the subclass's __invoke method.
     *
     * When using SequentialExecutor, branches execute sequentially.
     * When using ParallelExecutor, branches execute concurrently.
     *
     * @param Event $event The event that triggered this node
     * @param WorkflowState $state The workflow state
     * @return Event The result of merge()
     */
    protected function executeParallelNode(Event $event, WorkflowState $state): Event
    {
        // Get branch definitions
        $branches = $this->branches($event, $state);

        if ($branches === []) {
            return $this->merge([], $state);
        }

        // When called directly (SequentialExecutor), just pass through
        // The ParallelExecutor will intercept and run branches concurrently
        $results = [];
        foreach ($branches as $branchId => $branchEvent) {
            $results[$branchId] = $branchEvent;
        }

        return $this->merge($results, $state);
    }

    /**
     * Called when a branch emits events during execution.
     * Override to capture intermediate events from branches for streaming.
     *
     * @param string $branchId The branch identifier
     * @param Event $event The event emitted by the branch
     */
    public function onBranchEvent(string $branchId, Event $event): void
    {
        // Default: no-op
    }

    /**
     * Handle branch failure.
     *
     * @param string $branchId The branch that failed
     * @param Throwable $error The error that occurred
     * @return bool true to continue with other branches, false to abort all branches
     */
    public function onBranchError(string $branchId, Throwable $error): bool
    {
        return false; // Default: abort on any branch error
    }
}
