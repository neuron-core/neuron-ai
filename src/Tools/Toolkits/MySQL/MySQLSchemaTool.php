<?php

namespace NeuronAI\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Tool;
use PDO;

class MySQLSchemaTool extends Tool
{
    public function __construct(
        protected PDO $pdo,
        protected ?array $tables = null,
    ) {
        parent::__construct(
            'analyze_mysql_database_schema',
            'Retrieves MySQL database schema information including tables, columns, relationships, and indexes.
            Use this tool first to understand the database structure before writing any SQL queries.
            Essential for generating accurate queries with proper table/column names, JOIN conditions,
            and performance optimization. If you already know the database structure, you can skip this step.'
        );

        $this->setCallable($this);
    }

    public function __invoke()
    {
        return $this->formatForLLM([
            'tables' => $this->getTables(),
            'relationships' => $this->getRelationships(),
            'indexes' => $this->getIndexes(),
            'constraints' => $this->getConstraints()
        ]);
    }

    protected function formatForLLM(array $structure): string
    {
        $output = "# MySQL Database Schema Analysis\n\n";
        $output .= "This database contains " . \count($structure['tables']) . " tables with the following structure:\n\n";

        // Tables overview
        $output .= "## Tables Overview\n";
        foreach ($structure['tables'] as $table) {
            $pkColumns = empty($table['primary_key']) ? 'None' : \implode(', ', $table['primary_key']);
            $output .= "- **{$table['name']}**: {$table['estimated_rows']} rows, Primary Key: {$pkColumns}";
            if ($table['comment']) {
                $output .= " - {$table['comment']}";
            }
            $output .= "\n";
        }
        $output .= "\n";

        // Detailed table structures
        $output .= "## Detailed Table Structures\n\n";
        foreach ($structure['tables'] as $table) {
            $output .= "### Table: `{$table['name']}`\n";
            if ($table['comment']) {
                $output .= "**Description**: {$table['comment']}\n";
            }
            $output .= "**Estimated Rows**: {$table['estimated_rows']}\n\n";

            $output .= "**Columns**:\n";
            foreach ($table['columns'] as $column) {
                $nullable = $column['nullable'] ? 'NULL' : 'NOT NULL';
                $autoInc = $column['auto_increment'] ? ' AUTO_INCREMENT' : '';
                $default = $column['default'] !== null ? " DEFAULT '{$column['default']}'" : '';

                $output .= "- `{$column['name']}` {$column['full_type']} {$nullable}{$default}{$autoInc}";
                if ($column['comment']) {
                    $output .= " - {$column['comment']}";
                }
                $output .= "\n";
            }

            if (!empty($table['primary_key'])) {
                $output .= "\n**Primary Key**: " . \implode(', ', $table['primary_key']) . "\n";
            }

            if (!empty($table['unique_keys'])) {
                $output .= "**Unique Keys**: " . \implode(', ', $table['unique_keys']) . "\n";
            }

            $output .= "\n";
        }

        // Relationships
        if (!empty($structure['relationships'])) {
            $output .= "## Foreign Key Relationships\n\n";
            $output .= "Understanding these relationships is crucial for JOIN operations:\n\n";

            foreach ($structure['relationships'] as $rel) {
                $output .= "- `{$rel['source_table']}.{$rel['source_column']}` → `{$rel['target_table']}.{$rel['target_column']}`";
                $output .= " (ON DELETE {$rel['DELETE_RULE']}, ON UPDATE {$rel['UPDATE_RULE']})\n";
            }
            $output .= "\n";
        }

        // Indexes for query optimization
        if (!empty($structure['indexes'])) {
            $output .= "## Available Indexes (for Query Optimization)\n\n";
            $output .= "These indexes can significantly improve query performance:\n\n";

            foreach ($structure['indexes'] as $index) {
                $unique = $index['unique'] ? 'UNIQUE ' : '';
                $columns = \implode(', ', $index['columns']);
                $output .= "- {$unique}INDEX `{$index['name']}` on `{$index['table']}` ({$columns})\n";
            }
            $output .= "\n";
        }

        // Query generation guidelines
        $output .= "## MySQL Query Generation Guidelines\n\n";
        $output .= "**Best Practices for this database**:\n";
        $output .= "1. Always use table aliases for better readability\n";
        $output .= "2. Prefer indexed columns in WHERE clauses for better performance\n";
        $output .= "3. Use appropriate JOINs based on the foreign key relationships listed above\n";
        $output .= "4. Consider the estimated row counts when writing queries - larger tables may need LIMIT clauses\n";
        $output .= "5. Pay attention to nullable columns when using comparison operators\n\n";

        // Common patterns
        $output .= "**Common Query Patterns**:\n";
        $this->addCommonPatterns($output, $structure['tables']);

        return $output;
    }

    protected function getTables(): array
    {
        $whereClause = "WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'";
        $params = [];

        // Add table filtering if specific tables are requested
        if (!empty($this->tables)) {
            $placeholders = \str_repeat('?,', \count($this->tables) - 1) . '?';
            $whereClause .= " AND t.TABLE_NAME IN ($placeholders)";
            $params = $this->tables;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                t.TABLE_NAME,
                t.ENGINE,
                t.TABLE_ROWS,
                t.TABLE_COMMENT,
                c.COLUMN_NAME,
                c.ORDINAL_POSITION,
                c.COLUMN_DEFAULT,
                c.IS_NULLABLE,
                c.DATA_TYPE,
                c.CHARACTER_MAXIMUM_LENGTH,
                c.NUMERIC_PRECISION,
                c.NUMERIC_SCALE,
                c.COLUMN_TYPE,
                c.COLUMN_KEY,
                c.EXTRA,
                c.COLUMN_COMMENT
            FROM INFORMATION_SCHEMA.TABLES t
            LEFT JOIN INFORMATION_SCHEMA.COLUMNS c ON t.TABLE_NAME = c.TABLE_NAME
                AND t.TABLE_SCHEMA = c.TABLE_SCHEMA
            $whereClause
            ORDER BY t.TABLE_NAME, c.ORDINAL_POSITION
        ");

        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tables = [];
        foreach ($results as $row) {
            $tableName = $row['TABLE_NAME'];

            if (!isset($tables[$tableName])) {
                $tables[$tableName] = [
                    'name' => $tableName,
                    'engine' => $row['ENGINE'],
                    'estimated_rows' => $row['TABLE_ROWS'],
                    'comment' => $row['TABLE_COMMENT'],
                    'columns' => [],
                    'primary_key' => [],
                    'unique_keys' => [],
                    'indexes' => []
                ];
            }

            if ($row['COLUMN_NAME']) {
                $column = [
                    'name' => $row['COLUMN_NAME'],
                    'type' => $row['DATA_TYPE'],
                    'full_type' => $row['COLUMN_TYPE'],
                    'nullable' => $row['IS_NULLABLE'] === 'YES',
                    'default' => $row['COLUMN_DEFAULT'],
                    'auto_increment' => \strpos($row['EXTRA'], 'auto_increment') !== false,
                    'comment' => $row['COLUMN_COMMENT']
                ];

                // Add length/precision info for better LLM understanding
                if ($row['CHARACTER_MAXIMUM_LENGTH']) {
                    $column['max_length'] = $row['CHARACTER_MAXIMUM_LENGTH'];
                }
                if ($row['NUMERIC_PRECISION']) {
                    $column['precision'] = $row['NUMERIC_PRECISION'];
                    $column['scale'] = $row['NUMERIC_SCALE'];
                }

                $tables[$tableName]['columns'][] = $column;

                // Track key information
                if ($row['COLUMN_KEY'] === 'PRI') {
                    $tables[$tableName]['primary_key'][] = $row['COLUMN_NAME'];
                } elseif ($row['COLUMN_KEY'] === 'UNI') {
                    $tables[$tableName]['unique_keys'][] = $row['COLUMN_NAME'];
                } elseif ($row['COLUMN_KEY'] === 'MUL') {
                    $tables[$tableName]['indexes'][] = $row['COLUMN_NAME'];
                }
            }
        }

        return $tables;
    }

    protected function getRelationships(): array
    {
        $whereClause = "WHERE kcu.TABLE_SCHEMA = DATABASE() AND kcu.REFERENCED_TABLE_NAME IS NOT NULL";
        $params = [];

        // Add table filtering if specific tables are requested
        if (!empty($this->tables)) {
            $placeholders = \str_repeat('?,', \count($this->tables) - 1) . '?';
            $whereClause .= " AND (kcu.TABLE_NAME IN ($placeholders) OR kcu.REFERENCED_TABLE_NAME IN ($placeholders))";
            $params = \array_merge($this->tables, $this->tables);
        }

        $stmt = $this->pdo->prepare("
            SELECT
                kcu.CONSTRAINT_NAME,
                kcu.TABLE_NAME as source_table,
                kcu.COLUMN_NAME as source_column,
                kcu.REFERENCED_TABLE_NAME as target_table,
                kcu.REFERENCED_COLUMN_NAME as target_column,
                rc.UPDATE_RULE,
                rc.DELETE_RULE
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            $whereClause
            ORDER BY kcu.TABLE_NAME, kcu.ORDINAL_POSITION
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getIndexes(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                TABLE_NAME,
                INDEX_NAME,
                COLUMN_NAME,
                SEQ_IN_INDEX,
                NON_UNIQUE,
                INDEX_TYPE,
                CARDINALITY
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
                AND INDEX_NAME != 'PRIMARY'
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
        ");

        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexes = [];
        foreach ($results as $row) {
            $key = $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];
            if (!isset($indexes[$key])) {
                $indexes[$key] = [
                    'table' => $row['TABLE_NAME'],
                    'name' => $row['INDEX_NAME'],
                    'unique' => $row['NON_UNIQUE'] == 0,
                    'type' => $row['INDEX_TYPE'],
                    'columns' => []
                ];
            }
            $indexes[$key]['columns'][] = $row['COLUMN_NAME'];
        }

        return \array_values($indexes);
    }

    protected function getConstraints(): array
    {
        $whereClause = "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE IN ('UNIQUE')";
        $params = [];

        // Add table filtering if specific tables are requested
        if (!empty($this->tables)) {
            $placeholders = str_repeat('?,', count($this->tables) - 1) . '?';
            $whereClause .= " AND TABLE_NAME IN ($placeholders)";
            $params = $this->tables;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                CONSTRAINT_NAME,
                TABLE_NAME,
                CONSTRAINT_TYPE
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            $whereClause
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function addCommonPatterns(string &$output, array $tables): void
    {
        // Find tables with timestamps for temporal queries
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (\in_array($column['type'], ['timestamp', 'datetime', 'date']) &&
                    (str_contains(\strtolower($column['name']), 'created') ||
                        str_contains(\strtolower($column['name']), 'updated'))) {
                    $output .= "- For temporal queries on `{$table['name']}`, use `{$column['name']}` column\n";
                    break;
                }
            }
        }

        // Find potential text search columns
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (\in_array($column['type'], ['varchar', 'text', 'longtext']) &&
                    (str_contains(\strtolower($column['name']), 'name') ||
                        str_contains(\strtolower($column['name']), 'title') ||
                        str_contains(\strtolower($column['name']), 'description'))) {
                    $output .= "- For text searches on `{$table['name']}`, consider using `{$column['name']}` with LIKE or FULLTEXT\n";
                    break;
                }
            }
        }

        $output .= "\n";
    }
}
