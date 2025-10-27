<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\Events\PreProcessed;
use NeuronAI\Observability\Events\PreProcessing;
use NeuronAI\Observability\Events\Retrieved;
use NeuronAI\Observability\Events\Retrieving;

trait HandleRagEvents
{
    public function ragRetrieving(object $source, string $event, Retrieving $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $id = \md5($data->question->getContent().$data->question->getRole());

        $this->segments[$id] = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.retrieval', "vector_retrieval( {$data->question->getContent()} )")
            ->setColor(self::STANDARD_COLOR);
    }

    public function ragRetrieved(object $source, string $event, Retrieved $data): void
    {
        $id = \md5($data->question->getContent().$data->question->getRole());

        if (\array_key_exists($id, $this->segments)) {
            $segment = $this->segments[$id];
            $segment->addContext('Data', [
                    'question' => $data->question->getContent(),
                    'documents' => \count($data->documents)
                ]);
            $segment->end();
        }
    }

    public function preProcessing(object $source, string $event, PreProcessing $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $segment = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.preprocessing', $data->processor)
            ->setColor(self::STANDARD_COLOR);

        $segment->addContext('Original', $data->original->jsonSerialize());

        $this->segments[$data->processor] = $segment;
    }

    public function preProcessed(object $source, string $event, PreProcessed $data): void
    {
        if (\array_key_exists($data->processor, $this->segments)) {
            $this->segments[$data->processor]
                ->end()
                ->addContext('Processed', $data->processed->jsonSerialize());
        }
    }

    public function postProcessing(object $source, string $event, PostProcessing $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $segment = $this->inspector
            ->startSegment(self::SEGMENT_TYPE.'.postprocessing', $data->processor)
            ->setColor(self::STANDARD_COLOR);

        $segment->addContext('Question', $data->question->jsonSerialize())
            ->addContext('Documents', $data->documents);

        $this->segments[$data->processor] = $segment;
    }

    public function postProcessed(object $source, string $event, PostProcessed $data): void
    {
        if (\array_key_exists($data->processor, $this->segments)) {
            $this->segments[$data->processor]
                ->end()
                ->addContext('PostProcess', $data->documents);
        }
    }
}
