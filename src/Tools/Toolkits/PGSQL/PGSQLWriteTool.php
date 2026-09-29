<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\PGSQL;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PDO;
use ReflectionException;

use function json_encode;
use function str_starts_with;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * @method static static make(PDO $pdo)
 */
class PGSQLWriteTool extends Tool
{
    protected string $name = 'pgsql_write_query';

    protected ?string $description = 'Use this tool to perform write operations against the PostgreSQL database (e.g. INSERT, UPDATE, DELETE).';

    public function __construct(protected PDO $pdo)
    {
    }

    /**
     * @throws ReflectionException
     * @throws ArrayPropertyException
     * @throws ToolException
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                'query',
                PropertyType::STRING,
                'The parameterized SQL write query with named placeholders (e.g., "INSERT INTO users (name, email) VALUES (:name, :email)" or "UPDATE users SET name = :name WHERE id = :id"). Use named parameters (:parameter_name) for all dynamic values. Add a RETURNING clause (e.g., "RETURNING id") to get generated keys or changed rows back.',
                true
            ),
            new ArrayProperty(
                'parameters',
                'Key-value pairs for parameter binding where keys match the named placeholders in the query (without the colon). Example: {"name": "John Doe", "email": "%john%", "id": 123}. Leave empty if no parameters are needed.',
                false,
                items: new ObjectProperty(
                    name: 'parameter',
                    properties: [
                        new ToolProperty('name', PropertyType::STRING, 'Parameter name', true),
                        new ToolProperty('value', PropertyType::STRING, 'Parameter value', true),
                    ]
                )
            ),
        ];
    }

    /**
     * @param array<array{name: string, value: string}>|null $parameters
     */
    public function __invoke(string $query, ?array $parameters = []): string
    {
        $statement = $this->pdo->prepare($query);

        // Bind parameters if provided
        $parameters ??= [];
        foreach ($parameters as $parameter) {
            $paramName = str_starts_with((string) $parameter['name'], ':') ? $parameter['name'] : ':' . $parameter['name'];
            $statement->bindValue($paramName, $parameter['value']);
        }

        $result = $statement->execute();

        if (!$result) {
            $errorInfo = $statement->errorInfo();
            return "Error executing query: " . ($errorInfo[2] ?? 'Unknown database error');
        }

        // No lastInsertId(): PostgreSQL's LASTVAL() is session-wide, so it can name another table's row
        $output = "Query executed successfully. {$statement->rowCount()} row(s) affected.";

        if ($statement->columnCount() > 0) {
            $output .= ' Returned rows: ' . json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }

        return $output;
    }
}
