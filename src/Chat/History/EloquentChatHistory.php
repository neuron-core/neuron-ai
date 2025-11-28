<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use Illuminate\Database\Eloquent\Model;
use NeuronAI\Chat\Messages\Message;

use function array_merge;

use const PHP_INT_MAX;

class EloquentChatHistory extends AbstractChatHistory
{
    /**
     * @param class-string<Model> $modelClass
     */
    public function __construct(
        protected string $threadId,
        protected string $modelClass,
        int $contextWindow = 50000
    ) {
        parent::__construct($contextWindow);
        $this->load();
    }

    protected function load(): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        /** @var array<string, mixed> $messages */
        $messages = $model->newQuery()
            ->where('thread_id', $this->threadId)
            ->orderBy('id')
            ->get()
            // @phpstan-ignore-next-line
            ->map($this->recordToArray(...))
            ->all();

        if (!empty($messages)) {
            $this->history = $this->deserializeMessages($messages);
        }
    }

    protected function onNewMessage(Message $message): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $model->newQuery()->create([
            'thread_id' => $this->threadId,
            'role' => $message->getRole(),
            'content' => $message->getContent(),
            'meta' => $this->serializeMessageMeta($message),
        ]);
    }

    protected function onTrimHistory(int $index): void
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        // Get the IDs to delete (first $index messages, ordered by id)
        $idsToDelete = $model->newQuery()
            ->select('id')
            ->where('thread_id', $this->threadId)
            ->orderBy('id')
            ->offset($index)
            ->limit(PHP_INT_MAX)
            ->pluck('id')
            ->toArray();

        // Delete the old messages
        $model->newQuery()
            ->where('thread_id', $this->threadId)
            ->whereIn('id', $idsToDelete)
            ->delete();
    }

    public function setMessages(array $messages): ChatHistoryInterface
    {
        $this->history = $messages;
        return $this;
    }

    protected function clear(): ChatHistoryInterface
    {
        /** @var Model $model */
        $model = new $this->modelClass();

        $model->newQuery()
            ->where('thread_id', $this->threadId)
            ->delete();

        $this->history = [];
        return $this;
    }

    /**
     * Convert an Eloquent model record to the array format expected by deserializeMessages.
     *
     * @return array<string, mixed>
     */
    protected function recordToArray(Model $record): array
    {
        $data = [
            'role' => $record->getAttribute('role'),
            'content' => $record->getAttribute('content'),
        ];

        // Merge meta fields if present
        if ($meta = $record->getAttribute('meta')) {
            return array_merge($data, (array) $meta);
        }

        return $data;
    }

    /**
     * Serialize message metadata for storage.
     *
     * @return array<string, mixed>
     */
    protected function serializeMessageMeta(Message $message): array
    {
        $serialized = $message->jsonSerialize();

        // Remove fields that are stored in separate columns
        unset($serialized['role'], $serialized['content']);

        return $serialized;
    }
}
