<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\PGSQL;

use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PDO;

/**
 * @method static static make(Pdo $pdo)
 */
class PGSQLToolkit extends AbstractToolkit
{
    public function __construct(protected PDO $pdo)
    {
    }

    public function guidelines(): ?string
    {
        return "These tools allow you to learn the database structure,
        getting detailed information about tables, columns, relationships, and constraints
        to generate and execute precise and efficient SQL queries for PostgreSQL databases.";
    }

    public function provide(): array
    {
        return [
            PGSQLSchemaTool::make($this->pdo),
            PGSQLSelectTool::make($this->pdo),
            PGSQLWriteTool::make($this->pdo),
        ];
    }
}
