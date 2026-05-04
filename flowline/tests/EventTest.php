<?php

declare(strict_types=1);

namespace Flowline\Tests;

use Flowline\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function testFromArray(): void
    {
        $event = Event::fromArray([
            'id' => 'evt-123',
            'name' => 'order/created',
            'data' => ['order_id' => 42],
            'user' => ['id' => 'usr-1'],
            'ts' => 1705586504000,
        ]);

        $this->assertSame('evt-123', $event->id);
        $this->assertSame('order/created', $event->name);
        $this->assertSame(['order_id' => 42], $event->data);
        $this->assertSame(['id' => 'usr-1'], $event->user);
        $this->assertSame(1705586504000, $event->timestamp);
    }

    public function testFromArrayDefaults(): void
    {
        $event = Event::fromArray([
            'name' => 'test/event',
        ]);

        $this->assertSame('', $event->id);
        $this->assertSame([], $event->data);
        $this->assertNull($event->user);
        $this->assertSame(0, $event->timestamp);
    }

    public function testAsTrigger(): void
    {
        $event = new Event(name: 'order/created');

        $this->assertSame('order/created', $event->name);
        $this->assertSame('', $event->id);

        $this->assertSame(['name' => 'order/created'], $event->toArray());
    }

    public function testToArrayOmitsEmptyOptionalFields(): void
    {
        $event = new Event(name: 'test');

        $array = $event->toArray();

        $this->assertArrayNotHasKey('id', $array);
        $this->assertArrayNotHasKey('data', $array);
        $this->assertArrayNotHasKey('user', $array);
        $this->assertArrayNotHasKey('ts', $array);
    }

    public function testToArrayWithAllFields(): void
    {
        $event = new Event(
            name: 'order/created',
            id: 'evt-123',
            data: ['order_id' => 42],
            user: ['id' => 'usr-1'],
            timestamp: 1705586504000,
        );

        $array = $event->toArray();

        $this->assertSame('evt-123', $array['id']);
        $this->assertSame('order/created', $array['name']);
        $this->assertSame(['order_id' => 42], $array['data']);
        $this->assertSame(['id' => 'usr-1'], $array['user']);
        $this->assertSame(1705586504000, $array['ts']);
    }
}
