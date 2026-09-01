<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History\Stub;

use Illuminate\Database\Eloquent\Model;

/**
 * Mock Eloquent Model for testing
 *
 * @property string $thread_id
 * @property string $role
 * @property string $content
 * @property array $meta
 */
class ChatMessage extends Model
{
    protected $table = 'chat_messages';
    protected $fillable = ['thread_id', 'role', 'content', 'meta'];
    protected $casts = [
        'content' => 'array',
        'meta' => 'array',
    ];
}
