<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use JsonException;
use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ChatHistoryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_diff;
use function array_map;
use function array_values;
use function basename;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function in_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const GLOB_BRACE;

class FileMessageStoreTest extends TestCase
{
    /**
     * A private parent holds the store directory, so anything written outside it is visible.
     */
    protected string $parent;

    protected string $directory;

    protected function setUp(): void
    {
        $this->parent = sys_get_temp_dir() . '/neuron_file_store_' . uniqid();
        $this->directory = $this->parent . '/store';
    }

    protected function tearDown(): void
    {
        foreach ([$this->directory, $this->parent] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (glob($directory . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
                if (is_file($entry)) {
                    unlink($entry);
                } elseif (is_dir($entry) && !in_array(basename($entry), ['.', '..'], true)) {
                    rmdir($entry);
                }
            }
            rmdir($directory);
        }
    }

    public function test_construction_does_not_create_the_directory(): void
    {
        $store = new FileMessageStore($this->directory);

        $this->assertSame([], $store->loadAll('thread'));
        $this->assertSame([], $store->loadActive('thread'));
        $this->assertDirectoryDoesNotExist($this->directory);
    }

    public function test_the_first_write_creates_the_directory(): void
    {
        (new FileMessageStore($this->directory))->append('thread', new UserMessage('Hello'));

        $this->assertFileExists($this->directory . '/neuron_thread.chat');
    }

    public function test_the_file_name_uses_the_prefix_and_extension(): void
    {
        (new FileMessageStore($this->directory, 'tenant1-', '.json'))->append('thread', new UserMessage('Hello'));

        $this->assertSame(['tenant1-thread.json'], $this->filesIn($this->directory));
    }

    public function test_the_file_holds_the_serialized_messages(): void
    {
        $message = (new UserMessage('Hello'))->addMetadata('source', 'web');

        (new FileMessageStore($this->directory))->append('thread', $message);

        $this->assertSame(
            json_encode([$message]),
            file_get_contents($this->directory . '/neuron_thread.chat')
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileThreadIds(): array
    {
        return [
            'parent directory' => ['../escape'],
            'grandparent directory' => ['../../escape'],
            'dot dot only' => ['..'],
            'dot only' => ['.'],
            'absolute path' => ['/tmp/escape'],
            'nested path' => ['a/b/c'],
            'windows separators' => ['..\\..\\escape'],
            'null byte' => ["thread\0.php"],
            'already encoded traversal' => ['%2e%2e%2fescape'],
            'newline' => ["thread\nname"],
        ];
    }

    #[DataProvider('hostileThreadIds')]
    public function test_a_thread_id_cannot_leave_the_directory(string $threadId): void
    {
        $store = new FileMessageStore($this->directory);
        $message = new UserMessage('Hello');

        $store->append($threadId, $message);

        $this->assertSame(['store'], $this->filesIn($this->parent));
        $this->assertCount(1, $this->filesIn($this->directory));
        $this->assertSame([$message->getId()], $this->ids($store->loadAll($threadId)));

        $store->clear($threadId);
        $this->assertSame([], $this->filesIn($this->directory));
    }

    /**
     * Threads stored by earlier releases are found only while the encoding stays the same.
     *
     * @return array<string, array{string, string}>
     */
    public static function encodedThreadIds(): array
    {
        return [
            'traversal' => ['../escape', 'neuron_..%2Fescape.chat'],
            'space' => ['a b', 'neuron_a%20b.chat'],
            'plus' => ['a+b', 'neuron_a%2Bb.chat'],
            'tilde' => ['a~b', 'neuron_a~b.chat'],
            'multibyte' => ['é', 'neuron_%C3%A9.chat'],
        ];
    }

    #[DataProvider('encodedThreadIds')]
    public function test_the_thread_id_is_percent_encoded_in_the_file_name(string $threadId, string $fileName): void
    {
        (new FileMessageStore($this->directory))->append($threadId, new UserMessage('Hello'));

        $this->assertSame([$fileName], $this->filesIn($this->directory));
    }

    public function test_writes_leave_no_temporary_file_behind(): void
    {
        $store = new FileMessageStore($this->directory);
        $store->append('thread', new UserMessage('Hello'));
        $store->append('thread', new AssistantMessage('Hi'));
        $store->archive('thread', 1);

        $this->assertSame(['neuron_thread.chat'], $this->filesIn($this->directory));
    }

    public function test_a_failed_write_keeps_the_previous_version(): void
    {
        $store = new FileMessageStore($this->directory);
        $store->append('thread', new UserMessage('Hello'));
        $path = $this->directory . '/neuron_thread.chat';
        $before = file_get_contents($path);

        try {
            $store->append('thread', new AssistantMessage("Not UTF-8: \xff"));
            $this->fail('A message that cannot be encoded should not be written.');
        } catch (JsonException) {
        }

        $this->assertSame($before, file_get_contents($path));
        $this->assertSame(['neuron_thread.chat'], $this->filesIn($this->directory));
    }

    public function test_a_directory_that_cannot_be_created_raises_an_error(): void
    {
        mkdir($this->parent);
        file_put_contents($this->directory, 'a file, not a directory');

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage("Directory '{$this->directory}' does not exist and could not be created.");

        (new FileMessageStore($this->directory))->append('thread', new UserMessage('Hello'));
    }

    public function test_a_thread_path_that_cannot_be_replaced_raises_an_error(): void
    {
        mkdir($this->directory, recursive: true);
        mkdir($this->directory . '/neuron_thread.chat');

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage("Unable to save the chat history file '{$this->directory}/neuron_thread.chat'.");

        (new FileMessageStore($this->directory))->append('thread', new UserMessage('Hello'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function corruptContents(): array
    {
        return [
            'truncated JSON' => ['[{"role":"user"'],
            'empty file' => [''],
            'JSON null' => ['null'],
            'JSON string' => ['"messages"'],
            'JSON number' => ['42'],
        ];
    }

    #[DataProvider('corruptContents')]
    public function test_a_corrupt_file_raises_an_error(string $content): void
    {
        mkdir($this->directory, recursive: true);
        file_put_contents($this->directory . '/neuron_thread.chat', $content);

        $this->expectException(ChatHistoryException::class);
        $this->expectExceptionMessage("The chat history file '{$this->directory}/neuron_thread.chat' is corrupt.");

        (new FileMessageStore($this->directory))->loadActive('thread');
    }

    public function test_a_corrupt_file_is_never_overwritten(): void
    {
        mkdir($this->directory, recursive: true);
        $path = $this->directory . '/neuron_thread.chat';
        file_put_contents($path, '[{"role":"user"');

        try {
            (new FileMessageStore($this->directory))->append('thread', new UserMessage('Hello'));
            $this->fail('Appending to a corrupt file should fail.');
        } catch (ChatHistoryException) {
        }

        $this->assertSame('[{"role":"user"', file_get_contents($path));
    }

    public function test_entries_without_an_identity_receive_one_on_the_next_write(): void
    {
        mkdir($this->directory, recursive: true);
        $path = $this->directory . '/neuron_thread.chat';
        file_put_contents($path, json_encode([
            ['role' => 'user', 'content' => 'Archived question', 'archived_at' => '2026-01-01T00:00:00+00:00'],
            ['role' => 'assistant', 'content' => 'Active answer'],
        ]));
        $store = new FileMessageStore($this->directory);

        $this->assertSame(['Active answer'], array_map(fn (Message $m): ?string => $m->getContent(), $store->loadActive('thread')));

        $store->append('thread', new UserMessage('Next question'));

        $ids = array_column(json_decode((string) file_get_contents($path), true), '__id');
        $this->assertCount(3, $ids);
        $this->assertSame($ids, $this->ids($store->loadAll('thread')));
    }

    public function test_archival_is_not_exposed_as_message_metadata(): void
    {
        $store = new FileMessageStore($this->directory);
        $store->append('thread', new UserMessage('Hello'));
        $store->archive('thread', 1);

        $this->assertNull($store->loadAll('thread')[0]->getMetadata('archived_at'));
    }

    public function test_archival_of_a_legacy_entry_is_not_exposed_as_message_metadata(): void
    {
        // Earlier releases stored the metadata beside the message fields, where archived_at also lands.
        mkdir($this->directory, recursive: true);
        file_put_contents($this->directory . '/neuron_thread.chat', json_encode([
            ['__id' => 'msg_1', 'role' => 'user', 'content' => 'Old', 'source' => 'web'],
        ]));
        $store = new FileMessageStore($this->directory);

        $store->archive('thread', 1);

        $loaded = $store->loadAll('thread')[0];
        $this->assertNull($loaded->getMetadata('archived_at'));
        $this->assertSame('web', $loaded->getMetadata('source'));
    }

    public function test_archival_stamps_the_oldest_active_entries_with_a_date(): void
    {
        $store = new FileMessageStore($this->directory);
        $store->append('thread', new UserMessage('Hello'));
        $store->append('thread', new AssistantMessage('Hi'));
        $store->append('thread', new UserMessage('Bye'));

        $store->archive('thread', 2);

        $entries = json_decode((string) file_get_contents($this->directory . '/neuron_thread.chat'), true);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $entries[0]['archived_at']);
        $this->assertSame($entries[0]['archived_at'], $entries[1]['archived_at']);
        $this->assertArrayNotHasKey('archived_at', $entries[2]);
    }

    public function test_an_earlier_archival_date_is_kept(): void
    {
        mkdir($this->directory, recursive: true);
        $path = $this->directory . '/neuron_thread.chat';
        file_put_contents($path, json_encode([
            ['__id' => 'msg_1', 'role' => 'user', 'content' => 'Old', 'archived_at' => '2020-01-01T00:00:00+00:00'],
            ['__id' => 'msg_2', 'role' => 'assistant', 'content' => 'Newer'],
        ]));

        (new FileMessageStore($this->directory))->archive('thread', 1);

        $entries = json_decode((string) file_get_contents($path), true);
        $this->assertSame('2020-01-01T00:00:00+00:00', $entries[0]['archived_at']);
        $this->assertArrayHasKey('archived_at', $entries[1]);
    }

    /**
     * @return string[]
     */
    protected function filesIn(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }

    /**
     * @param Message[] $messages
     * @return string[]
     */
    protected function ids(array $messages): array
    {
        return array_map(fn (Message $message): string => $message->getId(), $messages);
    }
}
