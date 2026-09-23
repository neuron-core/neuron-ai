<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const GLOB_BRACE;

class FileMessageStoreTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/neuron_file_store_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/{,.}*.chat*', GLOB_BRACE) ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
    }

    public function test_construction_does_not_create_the_directory(): void
    {
        $store = new FileMessageStore($this->directory);

        $this->assertSame([], $store->loadAll('thread'));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_the_first_write_creates_the_directory(): void
    {
        (new FileMessageStore($this->directory))->append('thread', new UserMessage('Hello'));

        $this->assertFileExists($this->directory . '/neuron_thread.chat');
    }

    public function test_a_corrupt_file_raises_an_error(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/neuron_thread.chat', '[{"role":"user"');

        $this->expectException(ChatHistoryException::class);

        (new FileMessageStore($this->directory))->loadActive('thread');
    }

    public function test_a_thread_id_cannot_leave_the_directory(): void
    {
        (new FileMessageStore($this->directory))->append('../escape', new UserMessage('Hello'));

        $this->assertFileExists($this->directory . '/neuron_..%2Fescape.chat');
        $this->assertFalse(is_file(dirname($this->directory) . '/escape.chat'));
    }

    public function test_entries_without_an_identity_receive_one_on_the_next_write(): void
    {
        mkdir($this->directory);
        $path = $this->directory . '/neuron_thread.chat';
        file_put_contents($path, json_encode([
            ['role' => 'user', 'content' => 'Archived question', 'archived_at' => '2026-01-01T00:00:00+00:00'],
            ['role' => 'assistant', 'content' => 'Active answer'],
        ]));
        $store = new FileMessageStore($this->directory);

        $this->assertSame(['Active answer'], array_map(fn ($m) => $m->getContent(), $store->loadActive('thread')));

        $store->append('thread', new UserMessage('Next question'));

        $ids = array_column(json_decode((string) file_get_contents($path), true), '__id');
        $this->assertCount(3, $ids);
        $this->assertSame($ids, array_map(fn ($m) => $m->getId(), $store->loadAll('thread')));
    }

    public function test_archival_is_not_exposed_as_message_metadata(): void
    {
        $store = new FileMessageStore($this->directory);
        $store->append('thread', new UserMessage('Hello'));
        $store->archive('thread', 1);

        $this->assertNull($store->loadAll('thread')[0]->getMetadata('archived_at'));
    }
}
