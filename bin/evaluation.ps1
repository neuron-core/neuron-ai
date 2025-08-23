#Requires -Version 3.0

param(
    [Parameter(ValueFromRemainingArguments=$true)]
    [string[]]$Arguments
)

# Find PHP executable
$phpBinary = Get-Command php -ErrorAction SilentlyContinue
if (-not $phpBinary) {
    Write-Error "Error: PHP not found in PATH. Please make sure PHP is installed and available in your PATH"
    exit 1
}

# Find autoloader
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$autoloaderPaths = @(
    Join-Path $scriptDir "..\vendor\autoload.php"
    Join-Path $scriptDir "..\..\..\..\autoload.php"
    Join-Path $scriptDir "..\..\..\autoload.php"
)

$autoloader = $null
foreach ($path in $autoloaderPaths) {
    if (Test-Path $path) {
        $autoloader = Resolve-Path $path
        break
    }
}

if (-not $autoloader) {
    Write-Error "Error: Could not find Composer autoloader. Please run 'composer install' first."
    exit 1
}

# Create temporary PHP script
$tempScript = [System.IO.Path]::GetTempFileName() + ".php"
$phpCode = @"
<?php
declare(strict_types=1);
require_once '$($autoloader -replace '\\', '\\')';
use NeuronAI\Evaluation\Console\EvaluationCommand;
try {
    `$command = new EvaluationCommand();
    `$exitCode = `$command->run(`$argv);
    exit(`$exitCode);
} catch (Throwable `$e) {
    fwrite(STDERR, "Fatal Error: " . `$e->getMessage() . "\n");
    exit(1);
}
"@

Set-Content -Path $tempScript -Value $phpCode -Encoding UTF8

try {
    # Prepare arguments for PHP
    $phpArgs = @($tempScript) + $Arguments
    
    # Execute PHP script
    & $phpBinary.Source @phpArgs
    $exitCode = $LASTEXITCODE
}
finally {
    # Clean up temporary file
    if (Test-Path $tempScript) {
        Remove-Item $tempScript -Force -ErrorAction SilentlyContinue
    }
}

exit $exitCode