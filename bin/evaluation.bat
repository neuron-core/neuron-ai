@echo off
setlocal enabledelayedexpansion

REM Find PHP executable
set PHP_BINARY=php
where php >nul 2>&1
if errorlevel 1 (
    echo Error: PHP not found in PATH
    echo Please make sure PHP is installed and available in your PATH
    exit /b 1
)

REM Find autoloader
set AUTOLOADER_FOUND=0
set SCRIPT_DIR=%~dp0

if exist "%SCRIPT_DIR%..\vendor\autoload.php" (
    set AUTOLOADER=%SCRIPT_DIR%..\vendor\autoload.php
    set AUTOLOADER_FOUND=1
) else if exist "%SCRIPT_DIR%..\..\..\..\autoload.php" (
    set AUTOLOADER=%SCRIPT_DIR%..\..\..\..\autoload.php
    set AUTOLOADER_FOUND=1
) else if exist "%SCRIPT_DIR%..\..\..\autoload.php" (
    set AUTOLOADER=%SCRIPT_DIR%..\..\..\autoload.php
    set AUTOLOADER_FOUND=1
)

if !AUTOLOADER_FOUND! == 0 (
    echo Error: Could not find Composer autoloader.
    echo Please run 'composer install' first.
    exit /b 1
)

REM Create temporary PHP script
set TEMP_SCRIPT=%TEMP%\neuron_evaluation_%RANDOM%.php
echo ^<?php > "%TEMP_SCRIPT%"
echo declare(strict_types=1); >> "%TEMP_SCRIPT%"
echo require_once '%AUTOLOADER:\=\\%'; >> "%TEMP_SCRIPT%"
echo use NeuronAI\Evaluation\Console\EvaluationCommand; >> "%TEMP_SCRIPT%"
echo try { >> "%TEMP_SCRIPT%"
echo     $command = new EvaluationCommand(); >> "%TEMP_SCRIPT%"
echo     $exitCode = $command->run($argv); >> "%TEMP_SCRIPT%"
echo     exit($exitCode); >> "%TEMP_SCRIPT%"
echo } catch (Throwable $e) { >> "%TEMP_SCRIPT%"
echo     fwrite(STDERR, "Fatal Error: " . $e->getMessage() . "\n"); >> "%TEMP_SCRIPT%"
echo     exit(1); >> "%TEMP_SCRIPT%"
echo } >> "%TEMP_SCRIPT%"

REM Execute PHP script with all arguments
%PHP_BINARY% "%TEMP_SCRIPT%" %*
set EXIT_CODE=%ERRORLEVEL%

REM Clean up temporary file
del "%TEMP_SCRIPT%" >nul 2>&1

exit /b %EXIT_CODE%