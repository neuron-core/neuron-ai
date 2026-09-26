<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Exceptions\ChatHistoryException;
use PDO;
use PHPUnit\Framework\TestCase;

class TableNameNewlineTest extends TestCase
{
    public function test_a_table_name_with_a_trailing_newline_is_rejected(): void
    {
        $pdo = new PDO('sqlite::memory:');

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage('Invalid table name');

        new SQLMessageStore($pdo, "chat_messages\n");
    }

    public function test_a_valid_table_name_is_accepted(): void
    {
        $store = new SQLMessageStore(new PDO('sqlite::memory:'), 'chat_messages');

        $this->assertInstanceOf(SQLMessageStore::class, $store);
    }
}
