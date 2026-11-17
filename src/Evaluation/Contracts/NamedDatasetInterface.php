<?php

declare(strict_types=1);

namespace NeuronAI\Evaluation\Contracts;

/**
 * A dataset that can tell the reader of the results which data the evaluator
 * ran on. The runner hands it to the output drivers with the results.
 */
interface NamedDatasetInterface extends DatasetInterface
{
    /**
     * The name of the data, such as the path of a file. Output drivers send it
     * with the results, so it must hold no secret.
     */
    public function getName(): string;
}
