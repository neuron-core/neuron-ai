<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Stub;

use LogicException;
use MongoDB\BSON\Int64;
use MongoDB\Driver\CursorInterface;
use MongoDB\Driver\Server;

/**
 * A MongoDB cursor over fixed rows, so search results can be mapped without a server.
 */
class ArrayCursor implements CursorInterface
{
    protected int $position = 0;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(protected array $rows)
    {
    }

    public function toArray(): array
    {
        return $this->rows;
    }

    public function current(): array|object|null
    {
        return $this->rows[$this->position] ?? null;
    }

    public function key(): ?int
    {
        return $this->valid() ? $this->position : null;
    }

    public function next(): void
    {
        $this->position++;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->rows[$this->position]);
    }

    public function getId(): Int64
    {
        return new Int64(0);
    }

    public function getServer(): Server
    {
        throw new LogicException('An offline cursor has no server.');
    }

    public function isDead(): bool
    {
        return !$this->valid();
    }

    public function setTypeMap(array $typemap): void
    {
    }
}
