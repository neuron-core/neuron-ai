<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use NeuronAI\Exceptions\ChatHistoryException;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const LOCK_EX;

class FileChatHistory extends AbstractChatHistory
{
    public function __construct(
        protected string $directory,
        protected string $key,
        int $contextWindow = 50000,
        protected string $prefix = 'neuron_',
        protected string $ext = '.chat'
    ) {
        parent::__construct($contextWindow);

        if (!is_dir($this->directory)) {
            if (!@mkdir($this->directory, 0755, true)) {
                throw new ChatHistoryException(
                    "Directory '{$this->directory}' does not exist and could not be created"
                );
            }
        }

        $this->load();
    }

    protected function load(): void
    {
        if (is_file($this->getFilePath())) {
            $messages = json_decode(file_get_contents($this->getFilePath()), true) ?? [];
            $this->history = $this->deserializeMessages($messages);
        }
    }

    protected function getFilePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->prefix.$this->key.$this->ext;
    }

    /**
     * @throws ChatHistoryException
     */
    public function setMessages(array $messages): ChatHistoryInterface
    {
        $this->updateFile();
        return $this;
    }

    protected function clear(): ChatHistoryInterface
    {
        if (file_exists($this->getFilePath()) && !unlink($this->getFilePath())) {
            throw new ChatHistoryException("Unable to delete the file '{$this->getFilePath()}'");
        }
        return $this;
    }

    protected function updateFile(): void
    {
        $content = json_encode($this->jsonSerialize());
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
