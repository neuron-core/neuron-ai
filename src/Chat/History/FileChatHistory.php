<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Exceptions\ChatHistoryException;

use function array_merge;
use function array_slice;
use function date;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function unlink;
use function mkdir;

use const DATE_ATOM;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

/**
 * Stores the whole thread in one JSON file. Messages trimmed out of the context
 * window stay in the file marked by archived_at, and only the unarchived entries
 * are loaded back.
 */
class FileChatHistory extends AbstractChatHistory
{
    /**
     * Serialized entries of the archived messages, written back on every save.
     *
     * @var array<int, array<string, mixed>>
     */
    protected array $archived = [];

    /**
     * @throws ChatHistoryException
     */
    public function __construct(
        protected string $directory,
        ?string $key = null,
        int $contextWindow = 50000,
        protected string $prefix = 'neuron_',
        protected string $ext = '.chat'
    ) {
        parent::__construct($contextWindow);

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o755, true)) {
            throw new ChatHistoryException(
                "Directory '{$this->directory}' does not exist and could not be created."
            );
        }

        if ($key !== null) {
            $this->setThreadId($key);
        }
    }

    protected function loadThread(): void
    {
        if (!is_file($this->getFilePath())) {
            return;
        }

        $active = [];

        foreach (json_decode(file_get_contents($this->getFilePath()), true) ?? [] as $entry) {
            if (isset($entry['archived_at'])) {
                $this->archived[] = $entry;
            } else {
                $active[] = $entry;
            }
        }

        $this->history = $this->deserializeMessages($active);
    }

    protected function onTrimHistory(int $index): void
    {
        $archivedAt = date(DATE_ATOM);

        foreach (array_slice($this->history, 0, $index) as $message) {
            $this->archived[] = array_merge($message->jsonSerialize(), ['archived_at' => $archivedAt]);
        }
    }

    /**
     * @throws ChatHistoryException
     */
    protected function getFilePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->prefix.$this->requireThreadId().$this->ext;
    }

    /**
     * @throws ChatHistoryException
     */
    protected function setMessages(array $messages): void
    {
        $this->updateFile();
    }

    /**
     * @throws ChatHistoryException
     */
    protected function clear(): void
    {
        $this->archived = [];

        if (file_exists($this->getFilePath()) && !unlink($this->getFilePath())) {
            throw new ChatHistoryException("Unable to delete the file '{$this->getFilePath()}'");
        }
    }

    /**
     * @throws ChatHistoryException
     */
    protected function updateFile(): void
    {
        $content = json_encode([...$this->archived, ...$this->history]);
        $filePath = $this->getFilePath();

        // Try to write with LOCK_EX first for thread safety
        $result = @file_put_contents($filePath, $content, LOCK_EX);

        // If LOCK_EX fails (e.g., on some Windows environments), write without the lock
        if ($result === false) {
            $result = file_put_contents($filePath, $content);
        }

        if ($result === false) {
            throw new ChatHistoryException("Unable to save the chat history to file '{$filePath}'");
        }
    }
}
