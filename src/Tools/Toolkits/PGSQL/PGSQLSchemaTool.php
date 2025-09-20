<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\PGSQL;

use NeuronAI\Tools\Tool;
use PDO;

/**
 * @method static static make(PDO $pdo, ?array $tables = null)
 */
class PGSQLSchemaTool extends Tool
{
    public function __construct(
        protected PDO $pdo,
        protected ?array $tables = null,
    ) {
        parent::__construct(
            'analyze_pgsql_database_schema',
            'Retrieves PostgreSQL database schema information including tables, columns, relationships, and indexes.
Use this tool first to understand the database structure before writing any SQL queries.
Essential for generating accurate queries with proper table/column names, JOIN conditions,
and performance optimization. If you already know the database structure, you can skip this step.'
        );
    }

    public function __invoke(): string
    {
        return $this->formatForLLM([
            'tables' => $this->getTables(),
            'relationships' => $this->getRelationships(),
            'indexes' => $this->getIndexes(),
            'constraints' => $this->getConstraints()
        ]);
    }

    protected function getTables(): array
    {
        $whereClause = "WHERE t.table_schema = current_schema() AND t.table_type = 'BASE TABLE'";
        $params = [];

        if ($this->tables !== null && $this->tables !== []) {
            $placeholders = [];
            foreach ($this->tables as $table) {
                $placeholders[] = '?';
                $params[] = $table;
            }
            $whereClause .= " AND t.table_name = ANY(ARRAY[" . \implode(',', $placeholders) . "])";
        }

        $stmt = $this->pdo->prepare("
            SELECT
                t.table_name,
                obj_description(pgc.oid) as table_comment,
                c.column_name,
                c.ordinal_position,
                c.column_default,
                c.is_nullable,
                c.data_type,
                c.character_maximum_length,
                c.numeric_precision,
                c.numeric_scale,
                c.udt_name,
                CASE
                    WHEN pk.column_name IS NOT NULL THEN 'PRI'
                    WHEN uk.column_name IS NOT NULL THEN 'UNI'
                    WHEN idx.column_name IS NOT NULL THEN 'MUL'
                    ELSE ''
                END as column_key,
                CASE
                    WHEN c.column_default LIKE 'nextval%' THEN 'auto_increment'
                    ELSE ''
                END as extra,
                col_description(pgc.oid, c.ordinal_position) as column_comment
            FROM information_schema.tables t
            LEFT JOIN information_schema.columns c ON t.table_name = c.table_name
                AND t.table_schema = c.table_schema
            LEFT JOIN pg_class pgc ON pgc.relname = t.table_name AND pgc.relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = current_schema())
            LEFT JOIN (
                SELECT ku.table_name, ku.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage ku ON tc.constraint_name = ku.constraint_name
                WHERE tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = current_schema()
            ) pk ON pk.table_name = c.table_name AND pk.column_name = c.column_name
            LEFT JOIN (
                SELECT ku.table_name, ku.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage ku ON tc.constraint_name = ku.constraint_name
                WHERE tc.constraint_type = 'UNIQUE' AND tc.table_schema = current_schema()
            ) uk ON uk.table_name = c.table_name AND uk.column_name = c.column_name
            LEFT JOIN (
                SELECT
                    t.relname as table_name,
                    a.attname as column_name
                FROM pg_index i
                JOIN pg_class t ON t.oid = i.indrelid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(i.indkey)
                JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE i.indisprimary = false AND i.indisunique = false AND n.nspname = current_schema()
            ) idx ON idx.table_name = c.table_name AND idx.column_name = c.column_name
            $whereClause
            ORDER BY t.table_name, c.ordinal_position
        ");

        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tables = [];
        foreach ($results as $row) {
            $tableName = $row['table_name'];

            if (!isset($tables[$tableName])) {
                $tables[$tableName] = [
                    'name' => $tableName,
                    'engine' => 'PostgreSQL',
                    'estimated_rows' => $this->getTableRowCount($tableName),
                    'comment' => $row['table_comment'],
                    'columns' => [],
                    'primary_key' => [],
                    'unique_keys' => [],
                    'indexes' => []
                ];
            }

            if ($row['column_name']) {
                // Map PostgreSQL types to a more readable format
                $fullType = $this->formatPostgreSQLType($row);

                $column = [
                    'name' => $row['column_name'],
                    'type' => $row['data_type'],
                    'full_type' => $fullType,
                    'nullable' => $row['is_nullable'] === 'YES',
                    'default' => $row['column_default'],
                    'auto_increment' => \str_contains((string) $row['extra'], 'auto_increment'),
                    'comment' => $row['column_comment']
                ];

                if ($row['character_maximum_length']) {
                    $column['max_length'] = $row['character_maximum_length'];
                }
                if ($row['numeric_precision']) {
                    $column['precision'] = $row['numeric_precision'];
                    $column['scale'] = $row['numeric_scale'];
                }

                $tables[$tableName]['columns'][] = $column;

                if ($row['column_key'] === 'PRI') {
                    $tables[$tableName]['primary_key'][] = $row['column_name'];
                } elseif ($row['column_key'] === 'UNI') {
                    $tables[$tableName]['unique_keys'][] = $row['column_name'];
                } elseif ($row['column_key'] === 'MUL') {
                    $tables[$tableName]['indexes'][] = $row['column_name'];
                }
            }
        }

        return $tables;
    }

    protected function getTableRowCount(string $tableName): string
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT n_tup_ins - n_tup_del as estimate
                FROM pg_stat_user_tables
                WHERE relname = ?
            ");
            $stmt->execute([$tableName]);
            $result = $stmt->fetchColumn();
            return $result !== false ? (string)$result : 'N/A';
        } catch (\Exception) {
            return 'N/A';
        }
    }

    private function formatPostgreSQLType(array $row): string
    {
        $type = $row['udt_name'] ?? $row['data_type'];

        // Handle specific PostgreSQL types
        if ($type === 'varchar' || $type === 'character varying') {
            $type = 'character varying';
        }
        if ($row['character_maximum_length']) {
            return "{$type}({$row['character_maximum_length']})";
        }
        if ($row['numeric_precision'] && $row['numeric_scale']) {
            return "{$type}({$row['numeric_precision']},{$row['numeric_scale']})";
        }

        if ($row['numeric_precision']) {
            return "{$type}({$row['numeric_precision']})";
        }

        return $type;
    }

    protected function getRelationships(): array
    {
        $whereClause = "WHERE tc.table_schema = current_schema()";
        $params = [];

        if ($this->tables !== null && $this->tables !== []) {
            $placeholders = [];
            foreach ($this->tables as $table) {
                $placeholders[] = '?';
                $params[] = $table;
            }

            $params = [
                ...$params,
                ...$params,
            ];

            $whereClause .= " AND (tc.table_name = ANY(ARRAY[" . \implode(',', $placeholders) . "]) OR ccu.table_name = ANY(ARRAY[" . \implode(',', $placeholders) . "]))";
        }

        $stmt = $this->pdo->prepare("
            SELECT
                tc.constraint_name,
                tc.table_name as source_table,
                kcu.column_name as source_column,
                ccu.table_name as target_table,
                ccu.column_name as target_column,
                rc.update_rule,
                rc.delete_rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON ccu.constraint_name = tc.constraint_name
                AND ccu.table_schema = tc.table_schema
            JOIN information_schema.referential_constraints rc
                ON tc.constraint_name = rc.constraint_name
                AND tc.table_schema = rc.constraint_schema
            $whereClause
            AND tc.constraint_type = 'FOREIGN KEY'
            ORDER BY tc.table_name
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function getIndexes(): array
    {
        $whereClause = "WHERE schemaname = current_schema() AND indexname NOT LIKE '%_pkey'";
        $params = [];

        if ($this->tables !== null && $this->tables !== []) {
            $placeholders = [];
            foreach ($this->tables as $table) {
                $placeholders[] = '?';
                $params[] = $table;
            }
            $whereClause .= " AND tablename = ANY(ARRAY[" . \implode(',', $placeholders) . "])";
        }

        $stmt = $this->pdo->prepare("
            SELECT
                schemaname,
                tablename,
                indexname,
                indexdef
            FROM pg_indexes
            $whereClause
            ORDER BY tablename, indexname
        ");

        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexes = [];
        foreach ($results as $row) {
            // Parse column names from index definition
            \preg_match('/\((.*?)\)/', (string) $row['indexdef'], $matches);
            $columnList = $matches[1] ?? '';
            $columns = \array_map('trim', \explode(',', $columnList));

            // Clean up column names (remove function calls, etc.)
            $cleanColumns = [];
            foreach ($columns as $col) {
                // Extract just the column name if it's wrapped in functions
                if (\preg_match('/([a-zA-Z_]\w*)/', $col, $colMatches)) {
                    $cleanColumns[] = $colMatches[1];
                }
            }

            $indexes[] = [
                'table' => $row['tablename'],
                'name' => $row['indexname'],
                'unique' => \str_contains((string) $row['indexdef'], 'UNIQUE'),
                'type' => $this->extractIndexType($row['indexdef']),
                'columns' => $cleanColumns === [] ? $columns : $cleanColumns
            ];
        }

        return $indexes;
    }

    protected function extractIndexType(string $indexDef): string
    {
        if (\str_contains($indexDef, 'USING gin')) {
            return 'GIN';
        }
        if (\str_contains($indexDef, 'USING gist')) {
            return 'GIST';
        }
        if (\str_contains($indexDef, 'USING hash')) {
            return 'HASH';
        }
        if (\str_contains($indexDef, 'USING brin')) {
            return 'BRIN';
        }
        return 'BTREE';
        // Default

    }

    protected function getConstraints(): array
    {
        $whereClause = "WHERE table_schema = current_schema() AND constraint_type IN ('UNIQUE', 'CHECK')";
        $params = [];

        if ($this->tables !== null && $this->tables !== []) {
            $placeholders = [];
            foreach ($this->tables as $table) {
                $placeholders[] = '?';
                $params[] = $table;
            }
            $whereClause .= " AND table_name = ANY(ARRAY[" . \implode(',', $placeholders) . "])";
        }

        $stmt = $this->pdo->prepare("
            SELECT
                constraint_name,
                table_name,
                constraint_type
            FROM information_schema.table_constraints
            $whereClause
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function formatForLLM(array $structure): string
    {
        $output = "# PostgreSQL Database Schema Analysis\n\n";
        $output .= "This PostgreSQL database contains " . \count($structure['tables']) . " tables with the following structure:\n\n";

        // Tables overview
        $output .= "## Tables Overview\n";
        $tableCount = \count($structure['tables']);
        $filteredNote = $this->tables !== null ? " (filtered to specified tables)" : "";
        $output .= "Analyzing {$tableCount} tables{$filteredNote}:\n";

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
                $autoInc = $column['auto_increment'] ? ' (SERIAL/SEQUENCE)' : '';
                $default = $column['default'] !== null ? " DEFAULT {$column['default']}" : '';

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
                $output .= " (ON DELETE {$rel['delete_rule']}, ON UPDATE {$rel['update_rule']})\n";
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
                $output .= "- {$unique}{$index['type']} INDEX `{$index['name']}` on `{$index['table']}` ({$columns})\n";
            }
            $output .= "\n";
        }

        // PostgreSQL-specific query guidelines
        $output .= "## PostgreSQL SQL Query Generation Guidelines\n\n";
        $output .= "**Best Practices for this PostgreSQL database**:\n";
        $output .= "1. Always use table aliases for better readability\n";
        $output .= "2. Prefer indexed columns in WHERE clauses for better performance\n";
        $output .= "3. Use appropriate JOINs based on the foreign key relationships listed above\n";
        $output .= "4. Use double quotes (\") for identifiers if they contain special characters or are case-sensitive\n";
        $output .= "5. PostgreSQL is case-sensitive for identifiers - use exact casing as shown above\n";
        $output .= "6. Use \? for parameterized queries in prepared statements\n";
        $output .= "7. LIMIT clause syntax: `SELECT ... LIMIT n OFFSET m`\n";
        $output .= "8. String comparisons are case-sensitive by default (use ILIKE for case-insensitive)\n";
        $output .= "9. Use single quotes (') for string literals, not double quotes\n";
        $output .= "10. PostgreSQL supports advanced features like arrays, JSON/JSONB, and full-text search\n";
        $output .= "11. Use RETURNING clause for INSERT/UPDATE/DELETE to get back modified data\n\n";

        // Common patterns
        $output .= "**Common PostgreSQL Query Patterns**:\n";
        $this->addCommonPatterns($output, $structure['tables']);

        return $output;
    }

    protected function addCommonPatterns(string &$output, array $tables): void
    {
        // Find tables with timestamps for temporal queries
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (\in_array($column['type'], ['timestamp without time zone', 'timestamp with time zone', 'timestamptz', 'date', 'time']) &&
                    (\str_contains(\strtolower((string) $column['name']), 'created') ||
                     \str_contains(\strtolower((string) $column['name']), 'updated'))) {
                    $output .= "- For temporal queries on `{$table['name']}`, use `{$column['name']}` column\n";
                    break;
                }
            }
        }

        // Find potential text search columns
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (\in_array($column['type'], ['character varying', 'varchar', 'text', 'character']) &&
                    (\str_contains(\strtolower((string) $column['name']), 'name') ||
                     \str_contains(\strtolower((string) $column['name']), 'title') ||
                     \str_contains(\strtolower((string) $column['name']), 'description'))) {
                    $output .= "- For text searches on `{$table['name']}`, consider using `{$column['name']}` with ILIKE, ~ (regex), or full-text search\n";
                    break;
                }
            }
        }

        // Find JSON/JSONB columns
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (\in_array($column['type'], ['json', 'jsonb'])) {
                    $output .= "- Table `{$table['name']}` has {$column['type']} column `{$column['name']}` - use JSON operators like ->, ->>, @>, ? for querying\n";
                }
            }
        }

        // Find array columns
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if (\str_contains((string) $column['full_type'], '[]')) {
                    $output .= "- Table `{$table['name']}` has array column `{$column['name']}` ({$column['full_type']}) - use array operators like ANY, ALL, @>\n";
                }
            }
        }

        // Find UUID columns
        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if ($column['type'] === 'uuid') {
                    $output .= "- Table `{$table['name']}` uses UUID for `{$column['name']}` - use gen_random_uuid() for generating new UUIDs\n";
                    break;
                }
            }
        }

        $output .= "\n";
    }
}
