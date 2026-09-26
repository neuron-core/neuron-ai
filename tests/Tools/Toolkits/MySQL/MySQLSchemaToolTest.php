<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\MySQL;

use NeuronAI\Tools\Toolkits\MySQL\MySQLSchemaTool;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function implode;
use function str_contains;

/**
 * MySQL is not available to the suite: the INFORMATION_SCHEMA answers are
 * canned, so these tests pin how the tool builds its lookups and renders them.
 */
class MySQLSchemaToolTest extends TestCase
{
    /**
     * @var array<array{sql: string, params: array<mixed>}>
     */
    protected array $executed = [];

    public function test_renders_tables_columns_and_keys(): void
    {
        $output = (new MySQLSchemaTool($this->informationSchema()))();

        $this->assertStringStartsWith(
            "# MySQL Database Schema Analysis\n\nThis database contains 3 tables with the following structure:\n\n## Tables Overview\n"
            . "- **users**: 42 rows, Primary Key: id - Registered people\n"
            . "- **posts**: 7 rows, Primary Key: id\n"
            . "- **audit**: 0 rows, Primary Key: None\n\n",
            $output
        );
        $this->assertStringContainsString(
            "### Table: `users`\n**Description**: Registered people\n**Estimated Rows**: 42\n\n**Columns**:\n"
            . "- `id` int unsigned NOT NULL AUTO_INCREMENT\n"
            . "- `email` varchar(255) NOT NULL - Login address\n"
            . "- `name` varchar(100) NULL DEFAULT 'anonymous'\n"
            . "- `created_at` datetime NULL\n"
            . "- `updated_at` timestamp NULL\n"
            . "\n**Primary Key**: id\n**Unique Keys**: email\n\n",
            $output
        );
        $this->assertStringContainsString(
            "### Table: `posts`\n**Estimated Rows**: 7\n\n**Columns**:\n"
            . "- `id` int NOT NULL AUTO_INCREMENT\n"
            . "- `user_id` int NOT NULL\n"
            . "- `title` text NOT NULL\n"
            . "- `description` longtext NULL\n"
            . "- `published` tinyint(1) NOT NULL DEFAULT '0'\n"
            . "\n**Primary Key**: id\n\n",
            $output
        );
        $this->assertStringContainsString("### Table: `audit`\n**Estimated Rows**: 0\n\n**Columns**:\n\n", $output);
    }

    public function test_renders_relationships_and_indexes(): void
    {
        $output = (new MySQLSchemaTool($this->informationSchema()))();

        $this->assertStringContainsString(
            "## Foreign Key Relationships\n\nUnderstanding these relationships is crucial for JOIN operations:\n\n"
            . "- `posts.user_id` → `users.id` (ON DELETE CASCADE, ON UPDATE NO ACTION)\n\n",
            $output
        );
        $this->assertStringContainsString(
            "- INDEX `posts_user_title` on `posts` (user_id, title)\n"
            . "- UNIQUE INDEX `users_email_unique` on `users` (email)\n\n",
            $output
        );
    }

    public function test_suggests_the_first_temporal_and_text_search_column_of_each_table(): void
    {
        $output = (new MySQLSchemaTool($this->informationSchema()))();

        $this->assertStringEndsWith(
            "**Common Query Patterns**:\n"
            . "- For temporal queries on `users`, use `created_at` column\n"
            . "- For text searches on `users`, consider using `name` with LIKE or FULLTEXT\n"
            . "- For text searches on `posts`, consider using `title` with LIKE or FULLTEXT\n\n",
            $output
        );
    }

    public function test_sections_without_data_are_omitted(): void
    {
        $output = (new MySQLSchemaTool($this->informationSchema(relationships: [], indexes: [])))();

        $this->assertStringNotContainsString('## Foreign Key Relationships', $output);
        $this->assertStringNotContainsString('## Available Indexes', $output);
    }

    /**
     * @return iterable<string, array{array<string>|null}>
     */
    public static function unfilteredProvider(): iterable
    {
        yield 'no filter' => [null];
        yield 'empty filter' => [[]];
    }

    /**
     * @param array<string>|null $tables
     */
    #[DataProvider('unfilteredProvider')]
    public function test_without_a_table_filter_every_lookup_is_unrestricted(?array $tables): void
    {
        (new MySQLSchemaTool($this->informationSchema(), $tables))();

        $this->assertCount(4, $this->executed);
        foreach ($this->executed as $query) {
            $this->assertSame([], $query['params']);
            $this->assertStringNotContainsString(' IN (?', $query['sql']);
        }
    }

    public function test_table_filter_is_bound_as_parameters(): void
    {
        (new MySQLSchemaTool($this->informationSchema(), ['users', 'posts']))();

        $this->assertSame(['users', 'posts'], $this->lookup('INFORMATION_SCHEMA.TABLES t')['params']);
        $this->assertStringContainsString('AND t.TABLE_NAME IN (?,?)', $this->lookup('INFORMATION_SCHEMA.TABLES t')['sql']);

        $this->assertSame(['users', 'posts', 'users', 'posts'], $this->lookup('KEY_COLUMN_USAGE')['params']);
        $this->assertStringContainsString(
            'AND (kcu.TABLE_NAME IN (?,?) OR kcu.REFERENCED_TABLE_NAME IN (?,?))',
            $this->lookup('KEY_COLUMN_USAGE')['sql']
        );

        $this->assertSame(['users', 'posts'], $this->lookup('TABLE_CONSTRAINTS')['params']);
        $this->assertStringContainsString('AND TABLE_NAME IN (?,?)', $this->lookup('TABLE_CONSTRAINTS')['sql']);
    }

    public function test_hostile_table_names_never_reach_the_sql_text(): void
    {
        $hostile = "users') OR 1=1 -- `x`";

        (new MySQLSchemaTool($this->informationSchema(), [$hostile]))();

        foreach ($this->executed as $query) {
            $this->assertStringNotContainsString($hostile, $query['sql']);
        }
        $this->assertSame([$hostile], $this->lookup('INFORMATION_SCHEMA.TABLES t')['params']);
    }

    public function test_lookups_are_scoped_to_the_current_database(): void
    {
        (new MySQLSchemaTool($this->informationSchema()))();

        foreach ($this->executed as $query) {
            $this->assertStringContainsString('= DATABASE()', $query['sql']);
        }
    }

    public function test_tool_takes_no_input(): void
    {
        $tool = new MySQLSchemaTool($this->createMock(PDO::class));

        $this->assertSame('analyze_mysql_database_schema', $tool->getName());
        $this->assertSame([], $tool->getProperties());
    }

    /**
     * @param array<array<string, mixed>>|null $relationships
     * @param array<array<string, mixed>>|null $indexes
     */
    protected function informationSchema(?array $relationships = null, ?array $indexes = null): PDO
    {
        $answers = [
            'INFORMATION_SCHEMA.TABLES t' => [
                $this->column('users', 42, 'Registered people', 'id', 'int', 'int unsigned', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('users', 42, 'Registered people', 'email', 'varchar', 'varchar(255)', 'NO', null, 'UNI', '', 'Login address'),
                $this->column('users', 42, 'Registered people', 'name', 'varchar', 'varchar(100)', 'YES', 'anonymous', 'MUL'),
                $this->column('users', 42, 'Registered people', 'created_at', 'datetime', 'datetime', 'YES'),
                $this->column('users', 42, 'Registered people', 'updated_at', 'timestamp', 'timestamp', 'YES'),
                $this->column('posts', 7, '', 'id', 'int', 'int', 'NO', null, 'PRI', 'auto_increment'),
                $this->column('posts', 7, '', 'user_id', 'int', 'int', 'NO', null, 'MUL'),
                $this->column('posts', 7, '', 'title', 'text', 'text', 'NO'),
                $this->column('posts', 7, '', 'description', 'longtext', 'longtext', 'YES'),
                $this->column('posts', 7, '', 'published', 'tinyint', 'tinyint(1)', 'NO', '0'),
                $this->column('audit', 0, '', null, null, null, null),
            ],
            'KEY_COLUMN_USAGE' => $relationships ?? [[
                'CONSTRAINT_NAME' => 'posts_user_fk',
                'source_table' => 'posts',
                'source_column' => 'user_id',
                'target_table' => 'users',
                'target_column' => 'id',
                'UPDATE_RULE' => 'NO ACTION',
                'DELETE_RULE' => 'CASCADE',
            ]],
            'STATISTICS' => $indexes ?? [
                $this->indexColumn('posts', 'posts_user_title', 'user_id', 1),
                $this->indexColumn('posts', 'posts_user_title', 'title', 1),
                $this->indexColumn('users', 'users_email_unique', 'email', 0),
            ],
            'TABLE_CONSTRAINTS' => [['CONSTRAINT_NAME' => 'users_email_unique', 'TABLE_NAME' => 'users', 'CONSTRAINT_TYPE' => 'UNIQUE']],
        ];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($answers): PDOStatement {
            foreach ($answers as $source => $rows) {
                if (str_contains($sql, $source)) {
                    return $this->statement($sql, $rows);
                }
            }

            $this->fail("Unexpected query: {$sql}");
        });

        return $pdo;
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    protected function statement(string $sql, array $rows): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(function (?array $params = null) use ($sql): bool {
            $this->executed[] = ['sql' => $sql, 'params' => $params ?? []];

            return true;
        });
        $statement->method('fetchAll')->willReturn($rows);

        return $statement;
    }

    /**
     * @return array{sql: string, params: array<mixed>}
     */
    protected function lookup(string $source): array
    {
        foreach ($this->executed as $query) {
            if (str_contains($query['sql'], $source)) {
                return $query;
            }
        }

        $this->fail("No lookup on {$source}; ran: " . implode(', ', array_column($this->executed, 'sql')));
    }

    /**
     * @return array<string, mixed>
     */
    protected function column(
        string $table,
        int $rows,
        string $comment,
        ?string $name,
        ?string $type,
        ?string $fullType,
        ?string $nullable,
        ?string $default = null,
        string $key = '',
        string $extra = '',
        string $columnComment = '',
    ): array {
        return [
            'TABLE_NAME' => $table,
            'ENGINE' => 'InnoDB',
            'TABLE_ROWS' => $rows,
            'TABLE_COMMENT' => $comment,
            'COLUMN_NAME' => $name,
            'ORDINAL_POSITION' => 1,
            'COLUMN_DEFAULT' => $default,
            'IS_NULLABLE' => $nullable,
            'DATA_TYPE' => $type,
            'CHARACTER_MAXIMUM_LENGTH' => null,
            'NUMERIC_PRECISION' => null,
            'NUMERIC_SCALE' => null,
            'COLUMN_TYPE' => $fullType,
            'COLUMN_KEY' => $key,
            'EXTRA' => $extra,
            'COLUMN_COMMENT' => $columnComment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function indexColumn(string $table, string $index, string $column, int $nonUnique): array
    {
        return [
            'TABLE_NAME' => $table,
            'INDEX_NAME' => $index,
            'COLUMN_NAME' => $column,
            'SEQ_IN_INDEX' => 1,
            'NON_UNIQUE' => $nonUnique,
            'INDEX_TYPE' => 'BTREE',
            'CARDINALITY' => 1,
        ];
    }
}
