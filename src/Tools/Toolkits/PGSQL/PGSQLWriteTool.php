<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\PGSQL;

use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PDO;

/**
 * @method static static make(PDO $pdo)
 */
class PGSQLWriteTool extends Tool
{
    public function __construct(protected PDO $pdo)
    {
        parent::__construct(
            'execute_write_query',
            'Use this tool to perform write operations against the PostgreSQL database (e.g. INSERT, UPDATE, DELETE).'
        );
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                'query',
                PropertyType::STRING,
                'The parameterized SQL write query with named placeholders (e.g., "INSERT INTO users (name, email) VALUES (:name, :email)" or "UPDATE users SET name = :name WHERE id = :id"). Use named parameters (:parameter_name) for all dynamic values.',
                true
            ),
            new ArrayProperty(
                'parameters',
                'Key-value pairs for parameter binding where keys match the named placeholders in the query (without the colon). Example: {"name": "John Doe", "email": "%john%", "id": 123}. Leave empty if no parameters are needed.',
                false,
            ),
        ];
    }

    public function __invoke(string $query, array $parameters = []): string
    {
        $statement = $this->pdo->prepare($query);

        // Bind parameters if provided
        foreach ($parameters as $key => $value) {
            $paramName = \str_starts_with((string) $key, ':') ? $key : ':' . $key;
            $statement->bindValue($paramName, $value);
        }

        $result = $statement->execute();

        if (!$result) {
            $errorInfo = $statement->errorInfo();
            return "Error executing query: " . ($errorInfo[2] ?? 'Unknown database error');
        }

        // Get the number of affected rows for feedback
        $rowCount = $statement->rowCount();

        // For INSERT operations, also return the last insert ID if available
        if (\str_starts_with($query, 'INSERT')) {
            $lastInsertId = $this->pdo->lastInsertId();
            if ($lastInsertId > 0) {
                return "Query executed successfully. {$rowCount} row(s) affected. Last insert ID: {$lastInsertId}";
            }
        }

        return "Query executed successfully. {$rowCount} row(s) affected.";
    }
}
