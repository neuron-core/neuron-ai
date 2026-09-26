<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use PDO;
use PHPUnit\Framework\TestCase;

use function json_encode;

class ForgedMetaIdentityTest extends TestCase
{
    public function test_the_stored_identity_wins_over_an_identity_nested_in_the_metadata(): void
    {
        $restored = (new MessageDeserializer())->deserialize([
            '__id' => 'msg_real',
            'role' => 'user',
            'content' => [['type' => 'text', 'content' => 'Hi']],
            '__meta' => ['__id' => 'msg_forged', 'note' => 'kept'],
        ]);

        $this->assertSame('msg_real', $restored->getId());
        $this->assertSame('kept', $restored->getMetadata('note'));
    }

    public function test_the_message_id_column_is_the_identity_of_the_record(): void
    {
        $store = new SqliteMessageStore();
        $pdo = (fn (): PDO => $this->pdo)->call($store);
        $pdo->prepare('INSERT INTO chat_messages (thread_id, message_id, role, content, meta) VALUES (?, ?, ?, ?, ?)')
            ->execute(['thread', 'msg_real', 'user', json_encode([['type' => 'text', 'content' => 'Hi']]), json_encode(['__meta' => ['__id' => 'msg_forged']])]);

        $loaded = $store->loadAll('thread')[0];

        $this->assertSame('msg_real', $loaded->getId());
        $this->assertSame([], $store->loadAll('thread', 5, 'msg_forged'));
    }

    public function test_a_replay_of_the_loaded_message_is_not_stored_twice(): void
    {
        $store = new SqliteMessageStore();
        $pdo = (fn (): PDO => $this->pdo)->call($store);
        $pdo->prepare('INSERT INTO chat_messages (thread_id, message_id, role, content, meta) VALUES (?, ?, ?, ?, ?)')
            ->execute(['thread', 'msg_real', 'user', json_encode([['type' => 'text', 'content' => 'Hi']]), json_encode(['__meta' => ['__id' => 'msg_forged']])]);

        $store->append('thread', $store->loadAll('thread')[0]);

        $this->assertCount(1, $store->loadAll('thread'));
    }
}
