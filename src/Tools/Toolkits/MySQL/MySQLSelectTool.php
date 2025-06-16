<?php

namespace NeuronAI\Tools\Toolkits\MySQL;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PDO;

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
            'execute_select_query',
            'Use this tool only to run SELECT query against the MySQL database.
            This the tool to use only to gather information from the MySQL database.'
        );

        $this->addProperty(
            new ToolProperty(
                'query',
                PropertyType::STRING,
                'The SELECT query you want to run against the database.',
                true
            )
        )->setCallable($this);
    }

    public function __invoke(string $query)
    {
        if (!$this->validateReadOnly($query)) {
            return "The query was rejected for security reasons.
            It looks like you are trying to run a write query using the read-only query tool.";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function validateReadOnly(string $query): bool
    {
        // Remove comments and normalize whitespace
        $cleanQuery = $this->sanitizeQuery($query);

        // Check if it starts with allowed statements
        $firstKeyword = $this->getFirstKeyword($cleanQuery);
        if (!in_array($firstKeyword, $this->allowedStatements)) {
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
        $query = preg_replace('/--.*$/m', '', $query);
        $query = preg_replace('/\/\*.*?\*\//s', '', $query);

        // Normalize whitespace
        return preg_replace('/\s+/', ' ', trim($query));
    }

    protected function getFirstKeyword(string $query): string
    {
        if (preg_match('/^\s*(\w+)/i', $query, $matches)) {
            return strtoupper($matches[1]);
        }
        return '';
    }

    protected function containsKeyword(string $query, string $keyword): bool
    {
        // Use word boundaries to avoid false positives
        return preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $query) === 1;
    }
}
