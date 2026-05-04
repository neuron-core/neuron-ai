<?php

declare(strict_types=1);

namespace Flowline;

/**
 * Internal control-flow exception used to yield execution back to the platform
 * after discovering or completing a step operation.
 */
final class StepPendingException extends \Exception
{
}
