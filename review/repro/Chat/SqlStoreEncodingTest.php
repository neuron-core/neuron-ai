<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use JsonException;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Chat\History\Stub\SqliteMessageStore;
use PHPUnit\Framework\TestCase;

class SqlStoreEncodingTest extends TestCase
{
    public function test_a_message_that_cannot_be_encoded_is_rejected_and_not_stored(): void
    {
        $store = new SqliteMessageStore();

        try {
            $store->append('thread', new UserMessage("Not UTF-8: \xff"));
            $this->fail('Expected append() to reject content that cannot be JSON encoded.');
        } catch (JsonException) {
            $this->assertSame([], $store->loadAll('thread'));
        }
    }

    public function test_metadata_that_cannot_be_encoded_is_rejected_and_not_stored(): void
    {
        $store = new SqliteMessageStore();
        $message = new UserMessage('valid');
        $message->addMetadata('note', "\xff");

        try {
            $store->append('thread', $message);
            $this->fail('Expected append() to reject metadata that cannot be JSON encoded.');
        } catch (JsonException) {
            $this->assertSame([], $store->loadAll('thread'));
        }
    }
}
