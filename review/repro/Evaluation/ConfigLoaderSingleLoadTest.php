<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Config;

use NeuronAI\Evaluation\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function chdir;
use function file_put_contents;
use function getcwd;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

class ConfigLoaderSingleLoadTest extends TestCase
{
    public function test_evaluation_php_is_executed_once_per_loader(): void
    {
        $directory = sys_get_temp_dir() . '/neuron_config_' . bin2hex(random_bytes(6));
        mkdir($directory);
        // A typical config bootstraps the application container once
        file_put_contents($directory . '/evaluation.php', <<<'PHP'
            <?php
            $GLOBALS['evaluation_config_loads'] = ($GLOBALS['evaluation_config_loads'] ?? 0) + 1;
            return ['resolver' => fn (string $class): object => new $class()];
            PHP);
        $GLOBALS['evaluation_config_loads'] = 0;
        $cwd = (string) getcwd();
        chdir($directory);

        try {
            // What EvaluationCommand reads during one `neuron evaluation --cache` run
            $loader = new ConfigLoader();
            $loader->getResolver();
            $loader->getRunner();
            $loader->getCachePath();
            $loader->getOutputDrivers();
        } finally {
            chdir($cwd);
            unlink($directory . '/evaluation.php');
            rmdir($directory);
        }

        $this->assertSame(1, $GLOBALS['evaluation_config_loads']);
    }
}
