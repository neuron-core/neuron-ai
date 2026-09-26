<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\GraphStore\Stub;

use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\DriverInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\TransactionConfiguration;
use Laudis\Neo4j\Types\CypherList;
use Laudis\Neo4j\Types\CypherMap;
use LogicException;
use Throwable;

use function array_map;
use function array_shift;
use function iterator_to_array;

/**
 * Offline Neo4j client: records every statement with its parameters and
 * answers with queued rows (or throws a queued exception), in FIFO order.
 */
class RecordingNeo4jClient implements ClientInterface
{
    /**
     * @var list<array{statement: string, parameters: array<string, mixed>}>
     */
    public array $runs = [];

    /**
     * @var list<list<array<string, mixed>>|Throwable>
     */
    protected array $responses = [];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function willReturn(array $rows): self
    {
        $this->responses[] = $rows;

        return $this;
    }

    public function willThrow(Throwable $exception): self
    {
        $this->responses[] = $exception;

        return $this;
    }

    public function run(string $statement, iterable $parameters = [], ?string $alias = null): SummarizedResult
    {
        $this->runs[] = ['statement' => $statement, 'parameters' => iterator_to_array($parameters)];

        $response = array_shift($this->responses) ?? [];

        if ($response instanceof Throwable) {
            throw $response;
        }

        $summary = null;

        return new SummarizedResult(
            $summary,
            array_map(static fn (array $row): CypherMap => new CypherMap($row), $response)
        );
    }

    public function runStatement(Statement $statement, ?string $alias = null): SummarizedResult
    {
        return $this->run($statement->getText(), $statement->getParameters(), $alias);
    }

    public function runStatements(iterable $statements, ?string $alias = null): CypherList
    {
        throw new LogicException('Not supported by the recording client.');
    }

    public function beginTransaction(?iterable $statements = null, ?string $alias = null, ?TransactionConfiguration $config = null): UnmanagedTransactionInterface
    {
        throw new LogicException('Not supported by the recording client.');
    }

    public function getDriver(?string $alias): DriverInterface
    {
        throw new LogicException('Not supported by the recording client.');
    }

    public function hasDriver(string $alias): bool
    {
        return false;
    }

    public function writeTransaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null): mixed
    {
        throw new LogicException('Not supported by the recording client.');
    }

    public function readTransaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null): mixed
    {
        throw new LogicException('Not supported by the recording client.');
    }

    public function transaction(callable $tsxHandler, ?string $alias = null, ?TransactionConfiguration $config = null): mixed
    {
        throw new LogicException('Not supported by the recording client.');
    }

    public function verifyConnectivity(?string $driver = null): bool
    {
        return true;
    }

    public function bindTransaction(?string $alias = null, ?TransactionConfiguration $config = null): void
    {
        throw new LogicException('Not supported by the recording client.');
    }

    public function commitBoundTransaction(?string $alias = null, int $depth = 1): void
    {
        throw new LogicException('Not supported by the recording client.');
    }

    public function rollbackBoundTransaction(?string $alias = null, int $depth = 1): void
    {
        throw new LogicException('Not supported by the recording client.');
    }
}
