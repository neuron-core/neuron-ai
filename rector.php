<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationRector;

return RectorConfig::configure()
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
        AddReturnTypeDeclarationRector::class
    ])
    ->withSkip([
        \Rector\DeadCode\Rector\For_\RemoveDeadIfForeachForRector::class => [
            __DIR__ . '/src/Workflow/WorkflowHandler.php'
        ],
        \Rector\Php81\Rector\ClassMethod\NewInInitializerRector::class => [
            __DIR__ . '/src/Workflow/Workflow.php'
        ]
    ]);
