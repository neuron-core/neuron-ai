<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability;

use NeuronAI\Observability\ObserverAdapter;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Tests\Observability\Stub\CustomTestEvent;
use PHPUnit\Framework\TestCase;
use stdClass;

class ObserverAdapterTest extends TestCase
{
    public function test_forwards_the_stamped_emission_context(): void
    {
        $observer = $this->recordingObserver();
        $source = new stdClass();
        $event = new CustomTestEvent('value');
        $event->source = $source;
        $event->branchId = 'branch-a';

        (new ObserverAdapter($observer))($event);

        $this->assertSame([['custom-test-event', $source, $event, 'branch-a']], $observer->calls);
    }

    public function test_an_unstamped_event_is_its_own_source_on_the_main_branch(): void
    {
        $observer = $this->recordingObserver();
        $event = new CustomTestEvent('value');

        (new ObserverAdapter($observer))($event);

        $this->assertSame([['custom-test-event', $event, $event, '__main__']], $observer->calls);
    }

    /**
     * @return ObserverInterface&object{calls: array<int, array{string, object, mixed, string|null}>}
     */
    protected function recordingObserver(): ObserverInterface
    {
        return new class () implements ObserverInterface {
            /** @var array<int, array{string, object, mixed, string|null}> */
            public array $calls = [];

            public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
            {
                $this->calls[] = [$event, $source, $data, $branchId];
            }
        };
    }
}
