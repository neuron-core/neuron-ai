<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

/**
 * A tool input the model sent in a type the property cannot convert. Raised by
 * ToolPropertyInterface::cast() and settled by Tool::execute() as a conversational
 * error, so the model corrects the call instead of the run aborting.
 */
class InvalidToolInput extends ToolException
{
}
