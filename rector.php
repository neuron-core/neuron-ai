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
        strictBooleans: true,
    )
    ->withRules([
        AddReturnTypeDeclarationRector::class
    ])
    /*->withSkip([
        __DIR__ . '/src/Workflow/WorkflowHandler.php',
    ])*/;
