<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Stub;

use NeuronAI\Workflow\WorkflowState;
use stdClass;

class OwnedState extends WorkflowState
{
    public stdClass $details;

    public function __construct()
    {
        parent::__construct();
        $this->details = (object) ['count' => 0];
    }

    public function __clone(): void
    {
        parent::__clone();
        $this->details = clone $this->details;
    }
}
