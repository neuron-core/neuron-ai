<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console\Stub;

use NeuronAI\Console\Command;
use NeuronAI\Console\CommandProvider;

use function array_slice;

use const PHP_EOL;

class ArgumentsCommandProvider implements CommandProvider
{
    public function commands(): array
    {
        return [
            'arguments:echo' => ['Print each argument on its own line', fn (): Command => new class () extends Command {
                public function run(array $args): int
                {
                    foreach (array_slice($args, 1) as $arg) {
                        echo $arg . PHP_EOL;
                    }

                    return 0;
                }
            }],
        ];
    }
}
