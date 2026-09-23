<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use JsonException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\MessageDeserializer;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\UniqueIdGenerator;

use function array_filter;
use function array_map;
use function array_values;
use function date;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function rawurlencode;
use function rename;
use function strlen;
use function tempnam;
use function unlink;

use const DATE_ATOM;
use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;

/**
 * Stores each thread in one JSON file; archived messages stay in it, marked by
 * archived_at. A write replaces the file atomically, so a reader sees the previous
 * or the next version, never a partial one. Like FilePersistence, it suits
 * controlled single-host use: concurrent workers need a database store.
 */
class FileMessageStore implements MessageStoreInterface
{
    use PaginatesMessages;

    public function __construct(
        protected string $directory,
        protected string $prefix = 'neuron_',
        protected string $ext = '.chat',
    ) {
    }

    /**
     * @throws ChatHistoryException
     */
    public function loadActive(string $threadId): array
    {
        return $this->deserialize(array_filter(
            $this->read($threadId),
            fn (array $entry): bool => !isset($entry['archived_at'])
        ));
    }

    /**
     * @throws ChatHistoryException
     */
    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        return $this->paginate($this->deserialize($this->read($threadId)), $limit, $before);
    }

    /**
     * @throws ChatHistoryException
     */
    public function append(string $threadId, Message $message): void
    {
        $entries = $this->read($threadId);

        foreach ($entries as $entry) {
            if ($entry['__id'] === $message->getId()) {
                return;
            }
        }

        $entries[] = $message->jsonSerialize();

        $this->write($threadId, $entries);
    }

    /**
     * @throws ChatHistoryException
     */
    public function archive(string $threadId, int $count): void
    {
        $entries = $this->read($threadId);
        $archivedAt = date(DATE_ATOM);

        foreach ($entries as $index => $entry) {
            if ($count <= 0) {
                break;
            }

            if (!isset($entry['archived_at'])) {
                $entries[$index]['archived_at'] = $archivedAt;
                $count--;
            }
        }

        $this->write($threadId, $entries);
    }

    /**
     * @throws ChatHistoryException
     */
    public function clear(string $threadId): void
    {
        $path = $this->path($threadId);

        if (is_file($path) && !@unlink($path)) {
            throw new ChatHistoryException("Unable to delete the chat history file '{$path}'.");
        }
    }

    /**
     * Entries stored without an ID receive one here; the next write persists it.
     *
     * @return array<int, array<string, mixed>>
     * @throws ChatHistoryException
     */
    protected function read(string $threadId): array
    {
        $path = $this->path($threadId);

        if (!is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new ChatHistoryException("Unable to read the chat history file '{$path}'.");
        }

        $entries = json_decode($content, true);
        if (!is_array($entries)) {
            throw new ChatHistoryException("The chat history file '{$path}' is corrupt.");
        }

        foreach ($entries as $index => $entry) {
            $entries[$index]['__id'] ??= UniqueIdGenerator::generateId('msg_');
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @throws ChatHistoryException
     * @throws JsonException
     */
    protected function write(string $threadId, array $entries): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new ChatHistoryException("Directory '{$this->directory}' does not exist and could not be created.");
        }

        $path = $this->path($threadId);
        $temporaryPath = @tempnam($this->directory, '.chat-');
        if ($temporaryPath === false) {
            throw new ChatHistoryException("Unable to save the chat history file '{$path}'.");
        }

        try {
            $content = json_encode($entries, JSON_THROW_ON_ERROR);

            if (@file_put_contents($temporaryPath, $content) !== strlen($content) || !@rename($temporaryPath, $path)) {
                throw new ChatHistoryException("Unable to save the chat history file '{$path}'.");
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return Message[]
     */
    protected function deserialize(array $entries): array
    {
        $deserializer = new MessageDeserializer();

        return array_values(array_map(function (array $entry) use ($deserializer): Message {
            unset($entry['archived_at']);

            return $deserializer->deserialize($entry);
        }, $entries));
    }

    protected function path(string $threadId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $this->prefix . rawurlencode($threadId) . $this->ext;
    }
}
