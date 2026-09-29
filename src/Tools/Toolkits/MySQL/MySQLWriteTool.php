<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\MySQL;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use PDO;
use PDOException;
use ReflectionException;

use function json_encode;
use function str_starts_with;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * @method static static make(PDO $pdo)
 */
class MySQLWriteTool extends Tool
{
    protected string $name = 'mysql_write_query';

    protected ?string $description = 'Use this tool to perform write operations against the MySQL database (e.g. INSERT, UPDATE, DELETE).';

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
                'The parameterized SQL write query with named placeholders (e.g., "INSERT INTO users (name, email) VALUES (:name, :email)" or "UPDATE users SET name = :name WHERE id = :id"). Use named parameters (:parameter_name) for all dynamic values. Each placeholder name can be used only once: give each occurrence its own name, even for the same value (e.g., "UPDATE users SET status = :status WHERE id = :id OR parent_id = :parent_id").',
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
    public function __invoke(string $query, ?array $parameters = []): string|ToolOutput
    {
        try {
            $statement = $this->pdo->prepare($query);

            // Bind parameters if provided
            $parameters ??= [];
            foreach ($parameters as $parameter) {
                $paramName = str_starts_with((string) $parameter['name'], ':') ? $parameter['name'] : ':' . $parameter['name'];
                $statement->bindValue($paramName, $parameter['value']);
            }

            $result = $statement->execute();
        } catch (PDOException $exception) {
            return ToolOutput::error($exception->getMessage());
        }

        if (!$result) {
            $errorInfo = $statement->errorInfo();
            return ToolOutput::error("Error executing query: " . ($errorInfo[2] ?? 'Unknown database error'));
        }

        $output = "Query executed successfully. {$statement->rowCount()} row(s) affected.";

        // Tracked per statement: 0 unless this one generated an AUTO_INCREMENT value, the first one of a multi-row insert
        $generatedId = $this->pdo->lastInsertId();
        if ($generatedId > 0) {
            $output .= " First generated ID: {$generatedId}.";
        }

        if ($statement->columnCount() > 0) {
            $output .= ' Returned rows: ' . json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }

        return $output;
    }
}
