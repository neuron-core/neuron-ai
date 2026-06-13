<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools;

use NeuronAI\Tools\HasInterrupt;
use NeuronAI\Tools\InterruptHandler;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;

use function count;
use function implode;

class MultiStepTestTool extends Tool implements HasInterrupt
{
    use InterruptHandler;

    private int $currentStep = 0;

    public function __construct(/** @var string[] */
        private array $steps
    ) {
        parent::__construct(name: 'multi_step');
    }

    public function getCurrentStep(): int
    {
        return $this->currentStep;
    }

    public function __invoke(mixed ...$params): string
    {
        $resume = $this->getResumeRequest();

        if ($resume instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
            $this->currentStep++;
        }

        if ($this->currentStep >= count($this->steps)) {
            return 'All steps completed: '.implode(', ', $this->steps);
        }

        $this->setInterruptRequest(
            new ApprovalRequest($this->steps[$this->currentStep])
        );

        return '';
    }
}
