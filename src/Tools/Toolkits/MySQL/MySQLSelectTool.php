<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\MySQL;

use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PDO;

/**
 * @method static static make(PDO $pdo)
 */
class MySQLSelectTool extends Tool
{
    protected array $allowedStatements = ['SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'EXPLAIN'];

    protected array $forbiddenStatements = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER',
        'TRUNCATE', 'REPLACE', 'MERGE', 'CALL', 'EXECUTE'
    ];

    public function __construct(protected PDO $pdo)
    {
        parent::__construct(
            'mysql_select_query',
            'Use this tool only to run SELECT query against the MySQL database.
This the tool to use only to gather information from the MySQL database.'
        );
    }

    /**
     * @throws \ReflectionException
     * @throws ArrayPropertyException
     * @throws ToolException
     */
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'The parameterized SELECT query with named placeholders (e.g., "SELECT name, email FROM users WHERE name = :name". Use named parameters (:parameter_name) for all dynamic values.',
                required: true
            ),
            new ArrayProperty(
                name: 'parameters',
                description: 'Key-value pairs for parameter binding where keys match the named placeholders in the query (without the colon). Example: {"name": "John Doe", "email": "%john%", "id": 123}. Ignore if no parameters are needed.',
                required: false,
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

    public function __invoke(string $query, ?array $parameters = null): string|array
    {
        if (!$this->validateReadOnly($query)) {
            return "The query was rejected for security reasons.
            It looks like you are trying to run a write query using the read-only query tool.";
        }

        $statement = $this->pdo->prepare($query);

        // Bind parameters if provided
        foreach ($parameters as $parameter) {
            $paramName = \str_starts_with((string) $parameter['name'], ':') ? $parameter['name'] : ':' . $parameter['name'];
            $statement->bindValue($paramName, $parameter['value']);
        }

        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function validateReadOnly(string $query): bool
    {
        // Remove comments and normalize whitespace
        $cleanQuery = $this->sanitizeQuery($query);

        // Check if it starts with allowed statements
        $firstKeyword = $this->getFirstKeyword($cleanQuery);
        if (!\in_array($firstKeyword, $this->allowedStatements)) {
            return false;
        }

        // Check for forbidden keywords that might be in subqueries
        foreach ($this->forbiddenStatements as $forbidden) {
            if (self::containsKeyword($cleanQuery, $forbidden)) {
                return false;
            }
        }

        return true;
    }

    protected function sanitizeQuery(string $query): string
    {
        // Remove SQL comments
        $query = \preg_replace('/--.*$/m', '', $query);
        $query = \preg_replace('/\/\*.*?\*\//s', '', (string) $query);

        // Normalize whitespace
        return \preg_replace('/\s+/', ' ', \trim((string) $query));
    }

    protected function getFirstKeyword(string $query): string
    {
        if (\preg_match('/^\s*(\w+)/i', $query, $matches)) {
            return \strtoupper($matches[1]);
        }
        return '';
    }

    protected function containsKeyword(string $query, string $keyword): bool
    {
        // Use word boundaries to avoid false positives
        return \preg_match('/\b' . \preg_quote($keyword, '/') . '\b/i', $query) === 1;
    }
}
