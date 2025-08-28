<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\StartEvent;
use NeuronAI\Workflow\StopEvent;
use PHPUnit\Framework\TestCase;
use NeuronAI\Tests\Workflow\Stubs\FirstEvent;

class EventTest extends TestCase
{
    public function testEventInterface(): void
    {
        $event = new FirstEvent('test message');
        
        $this->assertInstanceOf(Event::class, $event);
        $this->assertEquals('test message', $event->message);
    }

    public function testStartEventDefaultConstruction(): void
    {
        $event = new StartEvent();
        
        $this->assertInstanceOf(Event::class, $event);
        $this->assertEmpty($event->getData());
    }

    public function testStartEventWithData(): void
    {
        $data = ['key1' => 'value1', 'key2' => 'value2'];
        $event = new StartEvent($data);
        
        $this->assertEquals($data, $event->getData());
        $this->assertEquals('value1', $event->get('key1'));
        $this->assertEquals('default', $event->get('nonexistent', 'default'));
    }

    public function testStartEventSetData(): void
    {
        $event = new StartEvent();
        $event->set('new_key', 'new_value');
        
        $this->assertEquals('new_value', $event->get('new_key'));
        $this->assertArrayHasKey('new_key', $event->getData());
    }

    public function testStopEventDefaultConstruction(): void
    {
        $event = new StopEvent();
        
        $this->assertInstanceOf(Event::class, $event);
        $this->assertNull($event->getResult());
    }

    public function testStopEventWithResult(): void
    {
        $result = 'workflow completed successfully';
        $event = new StopEvent($result);
        
        $this->assertEquals($result, $event->getResult());
    }

    public function testStopEventWithComplexResult(): void
    {
        $result = ['status' => 'success', 'data' => ['id' => 123]];
        $event = new StopEvent($result);
        
        $this->assertEquals($result, $event->getResult());
        $this->assertEquals('success', $event->getResult()['status']);
    }
}