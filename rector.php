<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationRector;

return RectorConfig::configure()
    ->withParallel(timeoutSeconds: 600, maxNumberOfProcess: 15)
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php81: true)
    ->withPreparedSets(
        codeQuality: true,
        deadCode: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withRules([
        AddReturnTypeDeclarationRector::class,
    ])
    // Renaming would change constructor parameter names, which are public API for named arguments.
    ->withConfiguredRule(ClassPropertyAssignToConstructorPromotionRector::class, [
        ClassPropertyAssignToConstructorPromotionRector::RENAME_PROPERTY => false,
    ])
    ->withSkip([
        __DIR__ . '/tests/Integration/Frontend/node_modules',
        Rector\DeadCode\Rector\For_\RemoveDeadLoopRector::class => [
            // Empty foreach loops that intentionally consume a lazy generator.
            __DIR__ . '/src/Workflow/Workflow.php',
            __DIR__ . '/tests/Stub/ExecutorTestHelpers.php',
        ],
        Rector\DeadCode\Rector\If_\ReduceAlwaysFalseIfOrRector::class => [
            // Runtime validation of a docblock-only type (list<string>) that callers can still violate.
            __DIR__ . '/src/Classifier/Score.php',
        ],
        ClassPropertyAssignToConstructorPromotionRector::class => [
            // A promoted default is not applied on unserialize: a run saved before $context existed must restore.
            __DIR__ . '/src/Agent/InferenceRequest.php',
        ],
        Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector::class => [
            // Fixtures deliberately declare invalid __invoke signatures to test node validation.
            __DIR__ . '/tests/Workflow/NodeSignatureTest.php',
        ],
    ]);
