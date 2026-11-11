<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Persistence;

/**
 * A store that can tell which partitions it holds. Separate from
 * PersistenceInterface only to keep direct implementations of it working:
 * this method moves into PersistenceInterface in the next major version.
 */
interface PersistenceListingInterface
{
    /**
     * The names of the partitions that hold a record and start with $prefix,
     * each one once, in no particular order; every partition for an empty
     * prefix. The prefix is compared byte by byte, like the names themselves.
     *
     * The list is not a snapshot: a partition written or removed while it is
     * built may or may not be in it.
     *
     * @phpstan-impure Every call lists what the store holds now.
     * @return iterable<string>
     */
    public function partitions(string $prefix = ''): iterable;
}
