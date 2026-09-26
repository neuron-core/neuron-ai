<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\FileMessageStore;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function is_link;
use function mkdir;
use function umask;

use const PHP_OS_FAMILY;

/**
 * Chat threads hold users' conversations: the store must never widen their
 * permissions or be steered by a symlink planted in its directory.
 */
class FileMessageStoreFilesystemSecurityTest extends TestCase
{
    use FileSystemSandbox;

    protected string $base;

    protected string $directory;

    protected int $umask;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions and symlinks are not available on Windows.');
        }

        // The common default umask, under which a plain file_put_contents() creates world-readable files.
        $this->umask = umask(0o022);
        $this->base = $this->createSandbox('neuron_chat_store');
        $this->directory = $this->base . '/store';
    }

    protected function tearDown(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        umask($this->umask);
        $this->removeSandbox($this->base);
    }

    public function test_thread_files_are_readable_by_their_owner_only(): void
    {
        $store = new FileMessageStore($this->directory);

        $path = $this->directory . '/neuron_thread.chat';

        $store->append('thread', new UserMessage('first'));
        $afterAppend = $this->groupAndOtherPermissions($path);
        $store->archive('thread', 1);
        $afterArchive = $this->groupAndOtherPermissions($path);

        $this->assertSame([0, 0], [$afterAppend, $afterArchive]);
    }

    public function test_a_write_replaces_a_planted_symlink_instead_of_writing_through_it(): void
    {
        mkdir($this->directory);
        $outside = $this->base . '/outside.json';
        file_put_contents($outside, '[]');
        $this->symlinkOrSkip($outside, $this->directory . '/neuron_thread.chat');

        (new FileMessageStore($this->directory))->append('thread', new UserMessage('private'));

        $this->assertFalse(is_link($this->directory . '/neuron_thread.chat'));
        $this->assertSame('[]', file_get_contents($outside));
        $this->assertSame(
            'private',
            (new FileMessageStore($this->directory))->loadAll('thread')[0]->getContent()
        );
    }

    public function test_clear_removes_a_planted_symlink_but_never_its_target(): void
    {
        mkdir($this->directory);
        $outside = $this->base . '/outside.json';
        file_put_contents($outside, '[]');
        $this->symlinkOrSkip($outside, $this->directory . '/neuron_thread.chat');

        (new FileMessageStore($this->directory))->clear('thread');

        $this->assertFalse(is_link($this->directory . '/neuron_thread.chat'));
        $this->assertSame('[]', file_get_contents($outside));
    }

    protected function groupAndOtherPermissions(string $path): int
    {
        clearstatcache();

        return fileperms($path) & 0o077;
    }
}
