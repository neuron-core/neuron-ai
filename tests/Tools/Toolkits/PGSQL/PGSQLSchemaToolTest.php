<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLSchemaTool;
use PDO;
use PHPUnit\Framework\TestCase;

use function preg_quote;

/**
 * Runs against the integration Postgres in a private schema. Row estimates
 * come from asynchronous statistics, so they are never asserted.
 */
class PGSQLSchemaToolTest extends TestCase
{
    protected PostgresSandbox $postgres;

    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->postgres = PostgresSandbox::open();
        $this->pdo = $this->postgres->pdo;

        $this->pdo->exec("
            CREATE TABLE users (
                id serial PRIMARY KEY,
                email varchar(120) NOT NULL UNIQUE,
                name text,
                meta jsonb,
                uid uuid,
                created_at timestamp DEFAULT now(),
                score numeric(8,2) CHECK (score >= 0)
            );
            COMMENT ON TABLE users IS 'People';
            COMMENT ON COLUMN users.email IS 'Login address';
            CREATE INDEX users_meta_idx ON users USING gin (meta);
            CREATE TABLE posts (
                id serial PRIMARY KEY,
                user_id int REFERENCES users(id) ON DELETE CASCADE,
                title text
            );
            CREATE INDEX posts_title_idx ON posts (title);
            CREATE TABLE audit (code text);
        ");
    }

    protected function tearDown(): void
    {
        $this->postgres->drop();
    }

    public function test_overview_lists_every_table_of_the_current_schema(): void
    {
        $output = (new PGSQLSchemaTool($this->pdo))();

        $this->assertStringStartsWith(
            "# PostgreSQL Database Schema Analysis\n\nThis PostgreSQL database contains 3 tables with the following structure:\n\n## Tables Overview\nAnalyzing 3 tables:\n",
            $output
        );
        $this->assertMatchesRegularExpression('/^- \*\*audit\*\*: \S+ rows, Primary Key: None$/m', $output);
        $this->assertMatchesRegularExpression('/^- \*\*posts\*\*: \S+ rows, Primary Key: id$/m', $output);
        $this->assertMatchesRegularExpression('/^- \*\*users\*\*: \S+ rows, Primary Key: id - People$/m', $output);
    }

    public function test_columns_are_rendered_with_types_nullability_defaults_and_comments(): void
    {
        $output = (new PGSQLSchemaTool($this->pdo))();

        $this->assertStringContainsString("### Table: `users`\n**Description**: People\n", $output);
        $this->assertMatchesRegularExpression(
            '/^- `id` \S+ NOT NULL DEFAULT ' . preg_quote("nextval('users_id_seq'::regclass) (SERIAL/SEQUENCE)", '/') . '$/m',
            $output
        );
        $this->assertStringContainsString("- `email` character varying(120) NOT NULL - Login address\n", $output);
        $this->assertStringContainsString("- `name` text NULL\n", $output);
        $this->assertStringContainsString("- `meta` jsonb NULL\n", $output);
        $this->assertStringContainsString("- `uid` uuid NULL\n", $output);
        $this->assertStringContainsString("- `created_at` timestamp NULL DEFAULT now()\n", $output);
        $this->assertStringContainsString("- `score` numeric(8,2) NULL\n", $output);
        $this->assertStringContainsString("\n**Primary Key**: id\n**Unique Keys**: email\n", $output);
    }

    public function test_foreign_keys_are_rendered_with_their_rules(): void
    {
        $this->assertStringContainsString(
            "- `posts.user_id` → `users.id` (ON DELETE CASCADE, ON UPDATE NO ACTION)\n",
            (new PGSQLSchemaTool($this->pdo))()
        );
    }

    public function test_indexes_are_rendered_without_primary_keys(): void
    {
        $output = (new PGSQLSchemaTool($this->pdo))();

        $this->assertStringContainsString(
            "- BTREE INDEX `posts_title_idx` on `posts` (title)\n"
            . "- UNIQUE BTREE INDEX `users_email_key` on `users` (email)\n"
            . "- GIN INDEX `users_meta_idx` on `users` (meta)\n",
            $output
        );
        $this->assertStringNotContainsString('_pkey', $output);
    }

    public function test_query_patterns_point_at_temporal_text_json_and_uuid_columns(): void
    {
        $this->assertStringEndsWith(
            "**Common PostgreSQL Query Patterns**:\n"
            . "- For temporal queries on `users`, use `created_at` column\n"
            . "- For text searches on `posts`, consider using `title` with ILIKE, ~ (regex), or full-text search\n"
            . "- For text searches on `users`, consider using `name` with ILIKE, ~ (regex), or full-text search\n"
            . "- Table `users` has jsonb column `meta` - use JSON operators like ->, ->>, @>, ? for querying\n"
            . "- Table `users` uses UUID for `uid` - use gen_random_uuid() for generating new UUIDs\n\n",
            (new PGSQLSchemaTool($this->pdo))()
        );
    }

    public function test_index_access_methods_and_column_kinds_are_named(): void
    {
        $this->pdo->exec('
            CREATE TABLE events (code text, updated_at timestamptz, location point, payload json);
            CREATE INDEX events_code_hash ON events USING hash (code);
            CREATE INDEX events_updated_brin ON events USING brin (updated_at);
            CREATE INDEX events_location_gist ON events USING gist (location);
        ');

        $output = (new PGSQLSchemaTool($this->pdo, ['events']))();

        $this->assertStringContainsString(
            "- HASH INDEX `events_code_hash` on `events` (code)\n"
            . "- GIST INDEX `events_location_gist` on `events` (location)\n"
            . "- BRIN INDEX `events_updated_brin` on `events` (updated_at)\n",
            $output
        );
        $this->assertStringEndsWith(
            "**Common PostgreSQL Query Patterns**:\n"
            . "- For temporal queries on `events`, use `updated_at` column\n"
            . "- Table `events` has json column `payload` - use JSON operators like ->, ->>, @>, ? for querying\n\n",
            $output
        );
    }

    public function test_table_filter_hides_every_other_table(): void
    {
        $output = (new PGSQLSchemaTool($this->pdo, ['posts']))();

        $this->assertStringContainsString("Analyzing 1 tables (filtered to specified tables):\n", $output);
        $this->assertStringContainsString('### Table: `posts`', $output);
        $this->assertStringNotContainsString('### Table: `users`', $output);
        $this->assertStringNotContainsString('audit', $output);
        $this->assertStringNotContainsString('users_email_key', $output);
        $this->assertStringNotContainsString('users_meta_idx', $output);
    }

    public function test_table_filter_keeps_relationships_pointing_at_the_filtered_table(): void
    {
        $this->assertStringContainsString(
            "- `posts.user_id` → `users.id` (ON DELETE CASCADE, ON UPDATE NO ACTION)\n",
            (new PGSQLSchemaTool($this->pdo, ['users']))()
        );
    }

    public function test_table_filter_matches_exact_case_sensitive_names(): void
    {
        $this->pdo->exec('CREATE TABLE "Order Items" (id int PRIMARY KEY)');

        $output = (new PGSQLSchemaTool($this->pdo, ['Order Items']))();

        $this->assertStringContainsString('This PostgreSQL database contains 1 tables', $output);
        $this->assertStringContainsString('### Table: `Order Items`', $output);
        $this->assertStringContainsString('This PostgreSQL database contains 0 tables', (new PGSQLSchemaTool($this->pdo, ['order items']))());
    }

    public function test_hostile_table_filter_is_bound_as_data(): void
    {
        $output = (new PGSQLSchemaTool($this->pdo, ["posts') OR 1=1 --", 'x"]); DROP TABLE users; --']))();

        $this->assertStringContainsString('This PostgreSQL database contains 0 tables', $output);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM pg_tables WHERE schemaname = current_schema() AND tablename = 'users'")->fetchColumn());
    }

    public function test_tables_of_other_schemas_are_not_described(): void
    {
        $other = PostgresSandbox::open();

        try {
            $other->pdo->exec('CREATE TABLE foreign_secrets (token text)');

            $this->assertStringNotContainsString('foreign_secrets', (new PGSQLSchemaTool($this->pdo))());
        } finally {
            $other->drop();
        }
    }

    public function test_tool_takes_no_input(): void
    {
        $tool = new PGSQLSchemaTool($this->pdo);

        $this->assertSame('analyze_pgsql_database_schema', $tool->getName());
        $this->assertSame([], $tool->getProperties());
    }
}
