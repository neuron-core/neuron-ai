<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor\Stubs;

use NeuronAI\Observability\ObserverInterface;

class RecordingObserver implements ObserverInterface
{
    /** @var array{event: string, branchId: string|null}[] */
    public array $recorded = [];

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        $this->recorded[] = [
            'event' => $event,
            'branchId' => $branchId,
        ];
    }
}
