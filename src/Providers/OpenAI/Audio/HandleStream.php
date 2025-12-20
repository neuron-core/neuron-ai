<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI\Audio;

use Generator;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Providers\SSEParser;
use NeuronAI\UniqueIdGenerator;

use function end;
use function is_array;

trait HandleStream
{
    /**
     * https://platform.openai.com/docs/api-reference/audio/speech-audio-delta-event
     *
     * @throws HttpException
     * @throws ProviderException
     */
    public function stream(array|Message $messages): Generator
    {
        $message = is_array($messages) ? end($messages) : $messages;

        $json = [
            'stream' => true,
            'model' => $this->model,
            'input' => $message->getContent(),
            'voice' => $this->voice,
            'instructions' => $this->system ?? '',
            ...$this->parameters
        ];

        $stream = $this->httpClient->stream(
            HttpRequest::post(
                uri: 'audio/speech',
                body: $json
            )
        );

        $content = '';
        $usage = new Usage(0, 0);
        $msgId = UniqueIdGenerator::generateId('msg_');

        while (! $stream->eof()) {
            if (!$line = SSEParser::parseNextSSEEvent($stream)) {
                continue;
            }

            // Delta
            if ($line['type'] === 'speech.audio.delta') {
                $content .= $line['audio'];

                yield new AudioChunk($msgId, $line['audio']);
            }

            // Done
            if ($line['type'] === 'speech.audio.done') {
                $usage->inputTokens = $line['usage']['input_tokens'] ?? 0;
                $usage->outputTokens = $line['usage']['output_tokens'] ?? 0;
            }
        }

        $message = new AssistantMessage(
            new AudioContent($content, SourceType::BASE64)
        );
        $message->setUsage($usage);
        return $message;
    }
}
