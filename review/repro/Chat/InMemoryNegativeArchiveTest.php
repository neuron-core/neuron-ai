<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\History;

use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

class InMemoryNegativeArchiveTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveCounts(): array
    {
        return ['zero' => [0], 'below archived prefix' => [-1], 'below zero overall' => [-3]];
    }

    #[DataProvider('nonPositiveCounts')]
    public function test_a_non_positive_archive_count_changes_nothing(int $count): void
    {
        $store = new InMemoryMessageStore();
        foreach ([new UserMessage('1'), new AssistantMessage('2'), new UserMessage('3'), new AssistantMessage('4')] as $message) {
            $store->append('thread', $message);
        }
        $store->archive('thread', 1);

        $store->archive('thread', $count);

        // SQL, Eloquent and File stores ignore a non-positive count.
        $this->assertSame(['2', '3', '4'], array_map(
            static fn (Message $message): string => (string) $message->getContent(),
            $store->loadActive('thread')
        ));
    }
}
