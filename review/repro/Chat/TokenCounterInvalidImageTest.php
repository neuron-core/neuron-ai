<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\TokenCounter;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;

class TokenCounterInvalidImageTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function undecodableImages(): array
    {
        return [
            'plain text payload' => [base64_encode('hello')],
            'svg markup' => [base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>')],
            'truncated png' => [base64_encode("\x89PNG\r\n\x1a\n")],
            'data uri with non image payload' => ['data:image/png;base64,' . base64_encode('hello')],
        ];
    }

    #[DataProvider('undecodableImages')]
    public function test_an_undecodable_base64_image_is_counted_without_error(string $payload): void
    {
        $message = new UserMessage([new ImageContent($payload, SourceType::BASE64, 'image/png')]);

        $this->assertGreaterThanOrEqual(1, (new TokenCounter())->count($message));
    }

    public function test_an_undecodable_image_attachment_does_not_break_the_conversation(): void
    {
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $message = new UserMessage([
            new TextContent('What is in this picture?'),
            new ImageContent(base64_encode('not an image'), SourceType::BASE64, 'image/png'),
        ]);

        $history->addMessage($message);

        $this->assertSame([$message], $history->getMessages());
    }
}
