<?php

namespace NeuronAI\Tools\Toolkits\PGSQL;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PDO;

class PGSQLWriteTool extends Tool
{
    public function __construct(protected PDO $pdo)
    {
        parent::__construct(
            'execute_write_query',
            'Use this tool to perform write operations against the PostgreSQL database (e.g. INSERT, UPDATE, DELETE).'
        );

        $this->addProperty(
            new ToolProperty(
                'query',
                PropertyType::STRING,
                'The write query you want to run against the PostgreSQL database.',
                true
            )
        )->setCallable($this);
    }

    public function __invoke(string $query)
    {
        $result = $this->pdo->prepare($query)->execute();

        return $result
            ? "The query has been executed successfully."
            : "I'm sorry, there was an error executing the query.";
    }
}
