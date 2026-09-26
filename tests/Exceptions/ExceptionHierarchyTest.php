<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Exceptions;

use Exception;
use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ChatHistoryException;
use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\InvalidMessage;
use NeuronAI\Exceptions\InvalidToolInput;
use NeuronAI\Exceptions\MissingCallbackParameter;
use NeuronAI\Exceptions\NeuronException;
use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\StreamAdapterException;
use NeuronAI\Exceptions\ToolCallableNotSet;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\Exceptions\WorkflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function get_parent_class;

/**
 * Callers catch these exceptions by their base classes (a tool error handler
 * catches ToolException, the engine catches WorkflowException, applications
 * catch NeuronException): re-parenting one silently changes who handles it.
 */
class ExceptionHierarchyTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function hierarchy(): iterable
    {
        yield 'NeuronException' => [NeuronException::class, Exception::class];
        yield 'AgentException' => [AgentException::class, NeuronException::class];
        yield 'ArrayPropertyException' => [ArrayPropertyException::class, NeuronException::class];
        yield 'ChatHistoryException' => [ChatHistoryException::class, NeuronException::class];
        yield 'DataReaderException' => [DataReaderException::class, NeuronException::class];
        yield 'HttpException' => [HttpException::class, NeuronException::class];
        yield 'InputTranslationException' => [InputTranslationException::class, NeuronException::class];
        yield 'InvalidMessage' => [InvalidMessage::class, NeuronException::class];
        yield 'MissingCallbackParameter' => [MissingCallbackParameter::class, NeuronException::class];
        yield 'ProviderException' => [ProviderException::class, NeuronException::class];
        yield 'StreamAdapterException' => [StreamAdapterException::class, NeuronException::class];
        yield 'VectorStoreException' => [VectorStoreException::class, NeuronException::class];
        yield 'ToolException' => [ToolException::class, NeuronException::class];
        yield 'InvalidToolInput' => [InvalidToolInput::class, ToolException::class];
        yield 'ToolCallableNotSet' => [ToolCallableNotSet::class, ToolException::class];
        yield 'ToolRunsExceededException' => [ToolRunsExceededException::class, ToolException::class];
        yield 'WorkflowException' => [WorkflowException::class, NeuronException::class];
        yield 'PersistenceException' => [PersistenceException::class, WorkflowException::class];
        yield 'RunInFlightException' => [RunInFlightException::class, WorkflowException::class];
        yield 'StaleWorkflowRunException' => [StaleWorkflowRunException::class, WorkflowException::class];
    }

    /**
     * @param class-string $exception
     * @param class-string $parent
     */
    #[DataProvider('hierarchy')]
    public function test_exception_extends_its_documented_parent(string $exception, string $parent): void
    {
        $this->assertSame($parent, get_parent_class($exception));
    }
}
