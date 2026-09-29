<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\HttpClient\StoppableStream;
use NeuronAI\HttpClient\StreamInterface;

use function array_filter;
use function array_values;

/**
 * A stream that ends without its closing event was either stopped by the
 * application, through StoppableHttpClient, or cut by the network. A cut must
 * never pass for a complete answer: it would be memoized and replayed forever.
 */
trait HandleEarlyStreamEnd
{
    /**
     * The answer of a stream stopped on purpose is the text streamed so far:
     * the reasoning and the tool calls it left incomplete are dropped, since
     * a provider would reject them on the next turn.
     *
     * @param ContentBlockInterface[] $blocks
     * @throws ProviderException when the stream was cut, or stopped before any text
     */
    protected function earlyEndResponse(StreamInterface $stream, array $blocks, string $messageId, Usage $usage): ProviderResponse
    {
        if (!$stream instanceof StoppableStream || !$stream->stopped()) {
            throw new ProviderException('The stream ended before the answer was complete.');
        }

        $text = array_values(array_filter(
            $blocks,
            static fn (ContentBlockInterface $block): bool => $block instanceof TextContent
                && !$block instanceof ReasoningContent
                && $block->content !== '',
        ));

        if ($text === []) {
            throw new ProviderException('The stream was stopped before the answer started.');
        }

        $message = (new AssistantMessage($text))
            ->setStopReason(StoppableHttpClient::STOP_REASON)
            ->setId($messageId)
            ->setUsage($usage);

        return new ProviderResponse(message: $message);
    }
}
