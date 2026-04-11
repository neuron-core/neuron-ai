<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use NeuronAI\Observability\Events\PostProcessed;
use NeuronAI\Observability\Events\PostProcessing;
use NeuronAI\Observability\Events\PreProcessed;
use NeuronAI\Observability\Events\PreProcessing;
use NeuronAI\Observability\Events\Retrieved;
use NeuronAI\Observability\Events\Retrieving;

use function array_key_exists;
use function count;
use function md5;

trait HandleRagEvents
{
    public function ragRetrieving(object $source, string $event, Retrieving $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $questionText = $data->question->getContent();
        $id = $branchId.':'.md5($questionText.$data->question->getRole());

        $this->segments[$id] = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.retrieval', "vector_retrieval( {$questionText} )")
            ->setColor(self::STANDARD_COLOR);
    }

    public function ragRetrieved(object $source, string $event, Retrieved $data, ?string $branchId = null): void
    {
        $questionText = $data->question->getContent();
        $id = $branchId.':'.md5($questionText.$data->question->getRole());

        if (array_key_exists($id, $this->segments)) {
            $segment = $this->segments[$id];
            $segment->addContext('Data', [
                    'question' => $questionText,
                    'documents' => count($data->documents),
                ]);
            $segment->end();
        }
    }

    public function preProcessing(object $source, string $event, PreProcessing $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $key = $branchId.':'.$data->processor;

        $segment = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.preprocessing', $data->processor)
            ->setColor(self::STANDARD_COLOR);

        $segment->addContext('Original', $data->original->jsonSerialize());

        $this->segments[$key] = $segment;
    }

    public function preProcessed(object $source, string $event, PreProcessed $data, ?string $branchId = null): void
    {
        $key = $branchId.':'.$data->processor;

        if (array_key_exists($key, $this->segments)) {
            $this->segments[$key]
                ->end()
                ->addContext('Processed', $data->processed->jsonSerialize());
        }
    }

    public function postProcessing(object $source, string $event, PostProcessing $data, ?string $branchId = null): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $key = $branchId.':'.$data->processor;

        $segment = $this->resolveScope($branchId)
            ->startSegment(self::SEGMENT_TYPE.'.postprocessing', $data->processor)
            ->setColor(self::STANDARD_COLOR);

        $segment->addContext('Question', $data->question->jsonSerialize())
            ->addContext('Documents', $data->documents);

        $this->segments[$key] = $segment;
    }

    public function postProcessed(object $source, string $event, PostProcessed $data, ?string $branchId = null): void
    {
        $key = $branchId.':'.$data->processor;

        if (array_key_exists($key, $this->segments)) {
            $this->segments[$key]
                ->end()
                ->addContext('PostProcess', $data->documents);
        }
    }
}
