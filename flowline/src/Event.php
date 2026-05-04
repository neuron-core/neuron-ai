<?php

declare(strict_types=1);

namespace Flowline;

/**
 * Represents both an inbound event (from the platform) and a trigger
 * definition (when registering tasks).
 *
 * As a trigger: new Event(name: 'order/created')
 * As an inbound event: Event::fromArray([...])
 */
final class Event
{
    /**
     * @param string $id Unique event identifier (empty when used as trigger)
     * @param string $name Event type in dot/slash notation (e.g. "order/created")
     * @param array $data Event payload
     * @param array|null $user Optional user context
     * @param int $timestamp Milliseconds since epoch
     */
    public function __construct(
        public readonly string $name,
        public readonly string $id = '',
        public readonly array $data = [],
        public readonly ?array $user = null,
        public readonly int $timestamp = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            id: $data['id'] ?? '',
            data: $data['data'] ?? [],
            user: $data['user'] ?? null,
            timestamp: $data['ts'] ?? 0,
        );
    }

    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
        ];
        if ($this->id !== '') {
            $result['id'] = $this->id;
        }
        if ($this->data !== []) {
            $result['data'] = $this->data;
        }
        if ($this->user !== null) {
            $result['user'] = $this->user;
        }
        if ($this->timestamp !== 0) {
            $result['ts'] = $this->timestamp;
        }
        return $result;
    }
}
