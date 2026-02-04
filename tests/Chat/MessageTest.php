<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat;

use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends Testcase
{
    public function test_retrieve_metadata()
    {
        $message = new Message(MessageRole::USER, 'Hi');
        $message->addMetadata('foo', 'bar');

        $this->assertSame('bar', $message->getMetadata('foo'));
    }

    public function test_retrieve_all_metadata_without_key()
    {
        $message = (new Message(MessageRole::USER, 'Hi'))
            ->addMetadata('foo', 'bar')
            ->addMetadata('biz', 'dev');

        $this->assertSame([
            'foo' => 'bar',
            'biz' => 'dev',
        ], $message->getMetadata());
    }
}
