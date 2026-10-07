<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

/**
 * Why the engine refused a request, or a write of a segment that lost its
 * run. The value is a stable code: a caller routes on it, never on the message.
 */
enum RefusalReason: string
{
    /** A start found the workflow ID held by a run that is not dead. */
    case RunInFlight = 'run_in_flight';

    /** A continuation found no run to continue. */
    case NoRun = 'no_run';

    /** The run the caller expected is not the one holding the workflow ID. */
    case StaleRun = 'stale_run';

    /** The execution attempt the caller expected, or owned, is no longer current. */
    case StaleAttempt = 'stale_attempt';

    /** A process is executing the run, or left it running. */
    case Executing = 'executing';

    /** The run is not waiting for the input it was given. */
    case NotAwaited = 'not_awaited';

    /** The run completed and its outcome is retained: acknowledge it instead. */
    case Completed = 'completed';

    /** The run has not completed, so it has no outcome to release. */
    case NotCompleted = 'not_completed';

    /** A concurrent process changed the run during the request: retry. */
    case Conflict = 'conflict';
}
