<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History\Stub;

use PDOStatement;

/**
 * A PDO statement class that records every executed query with its parameters.
 */
class RecordingStatement extends PDOStatement
{
    /** @var array<int, array{string, array<int|string, mixed>}> */
    public static array $executed = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$executed[] = [$this->queryString, $params ?? []];

        return parent::execute($params);
    }
}
