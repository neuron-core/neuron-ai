<?php

namespace NeuronAI\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PDO;

class MySQLToolkit extends AbstractToolkit
{
    public function __construct(protected PDO $pdo)
    {
    }

    public function guidelines(): ?string
    {
        return "These tools allow you to learn the database structure,
        getting detailed information about tables, columns, relationships, and constraints
        to generate and execute precise and efficient SQL queries.";
    }

    public function provide(): array
    {
        return [
            MySQLSchemaTool::make($this->pdo),
            MySQLSelectTool::make($this->pdo),
            MySQLWriteTool::make($this->pdo),
        ];
    }
}
