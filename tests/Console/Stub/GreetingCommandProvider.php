<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Console\Stub;

use NeuronAI\Console\Command;
use NeuronAI\Console\CommandProvider;

use const PHP_EOL;

class GreetingCommandProvider implements CommandProvider
{
    public function commands(): array
    {
        return [
            'greeting:hello' => ['Say hello', fn (): Command => new class () extends Command {
                public function run(array $args): int
                {
                    echo 'Hello from an installed package' . PHP_EOL;
                    return 0;
                }
            }],
        ];
    }
}
