<?php

declare(strict_types=1);

namespace NeuronAI\Chat\History;

use Illuminate\Database\Eloquent\Model;
use NeuronAI\Chat\Messages\Message;

/**
 * Migration:
 *
 * ```php
 * Schema::create('chat_messages', function (Blueprint $table) {
 *     $table->id();
 *     $table->string('thread_id')->index();
 *     $table->string('role');
 *     $table->json('content');
 *     $table->json('meta')->nullable();
 *     $table->timestamps();
 *
 *     $table->index(['thread_id', 'id']); // For efficient ordering and trimming
 * });
 * ```
 *
 * Example Model:
 * ```php
 * class ChatMessage extends Model
 * {
 *     protected $fillable = ['thread_id', 'role', 'content', 'meta'];
 *
 *     protected $casts = ['content' => 'array', 'meta' => 'array'];
 *
 *     public function conversation()
 *     {
 *         return $this->belongsTo(Conversation::class, 'thread_id');
 *     }
 * }
 * ```
 *
 * Usage:
 * ```php
 * $history = new EloquentChatHistory(
 *     threadId: 'conversation-123',
 *     modelClass: ChatMessage::class
 * );
 * ```
 */
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

        $messages = $model->newQuery()
            ->where('thread_id', $this->threadId)
            ->orderBy('id')
            ->get()
            ->map(fn ($record) => $this->recordToArray($record))
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
            'content' => $message->getContentBlocks(),
            'meta' => $this->serializeMessageMeta($message),
        ]);
    }

    protected function onTrimHistory(int $index): void
    {
        if ($index <= 0) {
            return;
        }

        /** @var Model $model */
        $model = new $this->modelClass();

        // Get the IDs of messages to keep (skip the first $index messages)
        $idsToKeep = $model->newQuery()
            ->where('thread_id', $this->threadId)
            ->orderBy('id')
            ->skip($index)
            ->pluck('id');

        // Delete messages not in the keep list
        $model->newQuery()
            ->where('thread_id', $this->threadId)
            ->whereNotIn('id', $idsToKeep)
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
            $data = array_merge($data, (array) $meta);
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
