<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\PGSQL;

use NeuronAI\Tests\Tools\Toolkits\PGSQL\Stub\PostgresSandbox;
use NeuronAI\Tools\Toolkits\PGSQL\PGSQLWriteTool;
use PHPUnit\Framework\TestCase;

class PGSQLWriteToolInsertWithoutSequenceTest extends TestCase
{
    public function test_insert_into_a_table_without_a_sequence_reports_success(): void
    {
        $postgres = PostgresSandbox::open();

        try {
            $postgres->pdo->exec('CREATE TABLE tags (label text)');

            $result = (new PGSQLWriteTool($postgres->pdo))('INSERT INTO tags (label) VALUES (:label)', [['name' => 'label', 'value' => 'php']]);

            $this->assertSame('Query executed successfully. 1 row(s) affected.', $result);
            $this->assertSame(1, (int) $postgres->pdo->query('SELECT count(*) FROM tags')->fetchColumn());
        } finally {
            $postgres->drop();
        }
    }
}
