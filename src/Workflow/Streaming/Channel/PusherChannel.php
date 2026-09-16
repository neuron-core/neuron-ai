<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Channel;

use InvalidArgumentException;
use Pusher\Pusher;
use Pusher\PusherCrypto;

use function count;
use function implode;
use function intdiv;
use function json_decode;
use function json_encode;
use function preg_match;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function max;

use const JSON_THROW_ON_ERROR;

final class PusherChannel extends AbstractChannel
{
    public function __construct(
        protected Pusher $client,
        protected string $channel,
        protected int    $maxRequestBytes = 10_000,
        protected int    $batchSize = 10,
    ) {
        if (preg_match('/\A[-a-zA-Z0-9_=@,.;]{1,164}\z/', $channel) !== 1) {
            throw new InvalidArgumentException('Invalid Pusher channel name.');
        }
        if ($batchSize < 1 || $batchSize > 50) {
            throw new InvalidArgumentException('Pusher batch size must be between 1 and 50.');
        }
        if ($maxRequestBytes <= strlen('{"batch":[]}')) {
            throw new InvalidArgumentException('Pusher request byte limit must leave room for events.');
        }
    }

    protected function batchSize(): int
    {
        return $this->batchSize;
    }

    protected function budget(): int
    {
        return $this->maxRequestBytes;
    }

    protected function eventBudget(): int
    {
        if (!PusherCrypto::is_encrypted_channel($this->channel)) {
            return 10_000;
        }

        // Secretbox adds 16 bytes; base64 uses four characters per three bytes.
        // Each base64 character may be a slash, escaped by the SDK's JSON encoder.
        return 3 * intdiv(10_000 - strlen($this->encryptedMetadata()), 8) - 16;
    }

    /** @param list<string> $events */
    protected function batch(array $events): string
    {
        return '{"batch":[' . implode(',', $events) . ']}';
    }

    /** @param list<string> $events */
    protected function batchBytes(array $events): int
    {
        if (!PusherCrypto::is_encrypted_channel($this->channel)) {
            return parent::batchBytes($events);
        }

        $bytes = strlen('{"batch":[]}') + max(0, count($events) - 1);
        foreach ($events as $encoded) {
            $event = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            $ciphertextCharacters = 4 * intdiv(strlen($event['data']) + 16 + 2, 3);
            $event['data'] = $this->encryptedMetadata();
            // The SDK JSON-encodes encrypted data twice: a slash can take four bytes.
            $bytes += strlen(json_encode($event, JSON_THROW_ON_ERROR)) + 4 * $ciphertextCharacters;
        }
        return $bytes;
    }

    protected function encryptedMetadata(): string
    {
        return json_encode(['nonce' => str_repeat('/', 32), 'ciphertext' => ''], JSON_THROW_ON_ERROR);
    }

    protected function deliver(string $batch): void
    {
        $this->client->triggerBatch(json_decode($batch, true, 512, JSON_THROW_ON_ERROR)['batch'], true);
    }

    protected function encode(string $type, string $envelope): string
    {
        if ($type === '' || strlen($type) > 200 || str_starts_with($type, 'pusher:')) {
            throw new InvalidArgumentException('Pusher event names must be 1–200 bytes and cannot start with pusher:.');
        }

        return json_encode([
            'channel' => $this->channel,
            'name' => $type,
            'data' => $envelope,
        ], JSON_THROW_ON_ERROR);
    }
}
