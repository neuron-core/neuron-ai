<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Assertions\Trajectory;

/**
 * Sequence-matching semantics for {@see TrajectoryMatches} (AgentEvals vocabulary).
 */
enum Mode: string
{
    /**
     * Exact sequence: the actual tool calls are exactly the expected names,
     * in order, nothing else.
     */
    case Strict = 'strict';

    /**
     * Same calls, any order: the actual names are the same multiset as the
     * expected names.
     */
    case Unordered = 'unordered';

    /**
     * Expected appears in order within the actual sequence; extra calls are
     * allowed anywhere.
     */
    case Subset = 'subset';

    /**
     * No call outside the expected set: every actual name is one of the
     * expected names (presence and order are not required).
     */
    case Superset = 'superset';
}
