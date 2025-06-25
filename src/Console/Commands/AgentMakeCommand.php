<?php

declare(strict_types=1);

namespace NeuronAI\Console\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class AgentMakeCommand extends GeneratorCommand
{
    protected $name = 'make:agent';
    
    protected $description = 'Create a new AI agent class';
    
    protected $type = 'Agent';

    /**
     * Get the stub file for the generator.
     */
    protected function getStub(): string
    {
        return __DIR__ . '/../stubs/agent.stub';
    }

    /**
     * Get the default namespace for the class.
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $this->option('path') 
            ? $rootNamespace . '\\' . str_replace('/', '\\', $this->option('path'))
            : $rootNamespace . '\\Agents';
    }

    /**
     * Build the class with the given name.
     */
    protected function buildClass($name): string
    {
        $stub = $this->files->get($this->getStub());

        return $this->replaceNamespace($stub, $name)
                    ->replaceClass($stub, $name)
                    ->replaceProvider($stub)
                    ->replaceInstructions($stub)
                    ->replaceTools($stub);
    }

    /**
     * Replace the provider implementation in the stub.
     */
    protected function replaceProvider(&$stub): static
    {
        $provider = $this->option('provider');
        
        if (!$provider) {
            $stub = str_replace('DummyProviderImport', '', $stub);
            $stub = str_replace(
                'DummyProviderImplementation', 
                '// TODO: Configure your AI provider here' . PHP_EOL . 
                '        // Example: return OpenAIProvider::make();',
                $stub
            );
        } else {
            $providerConfig = $this->getProviderConfig($provider);
            $stub = str_replace('DummyProviderImport', $providerConfig['import'], $stub);
            $stub = str_replace('DummyProviderImplementation', $providerConfig['implementation'], $stub);
        }
        
        return $this;
    }

    /**
     * Replace the instructions implementation in the stub.
     */
    protected function replaceInstructions(&$stub): static
    {
        $instructions = $this->option('instructions');
        
        if (!$instructions) {
            $stub = str_replace(
                'DummyInstructions',
                '// TODO: Define your agent instructions here' . PHP_EOL . 
                '        // Example: return \'You are a helpful AI assistant.\';',
                $stub
            );
        } else {
            $stub = str_replace('DummyInstructions', "return '{$instructions}';", $stub);
        }
        
        return $this;
    }

    /**
     * Replace the tools configuration in the stub.
     */
    protected function replaceTools(&$stub): static
    {
        $tools = $this->option('tools');
        
        if (!$tools) {
            $stub = str_replace('DummyToolsImports', '', $stub);
            $stub = str_replace('DummyToolsConfiguration', '', $stub);
        } else {
            $toolsArray = array_map('trim', explode(',', $tools));
            $toolsConfig = $this->generateToolsConfiguration($toolsArray);
            
            $stub = str_replace('DummyToolsImports', $toolsConfig['imports'], $stub);
            $stub = str_replace('DummyToolsConfiguration', $toolsConfig['configuration'], $stub);
        }
        
        return $this;
    }

    /**
     * Get the provider configuration.
     */
    protected function getProviderConfig(string $provider): array
    {
        $providerMap = [
            'openai' => [
                'import' => 'use NeuronAI\Providers\OpenAIProvider;',
                'implementation' => 'return OpenAIProvider::make();'
            ],
            'anthropic' => [
                'import' => 'use NeuronAI\Providers\AnthropicProvider;',
                'implementation' => 'return AnthropicProvider::make();'
            ],
            'gemini' => [
                'import' => 'use NeuronAI\Providers\GeminiProvider;',
                'implementation' => 'return GeminiProvider::make();'
            ],
            'ollama' => [
                'import' => 'use NeuronAI\Providers\OllamaProvider;',
                'implementation' => 'return OllamaProvider::make();'
            ],
        ];

        if (!isset($providerMap[$provider])) {
            $this->warn("Provider '{$provider}' not recognized. Using OpenAI as default.");
            return $providerMap['openai'];
        }

        return $providerMap[$provider];
    }

    /**
     * Generate tools configuration.
     */
    protected function generateToolsConfiguration(array $tools): array
    {
        $imports = [];
        $implementations = [];

        foreach ($tools as $tool) {
            $toolClass = Str::studly($tool) . 'Tool';
            $imports[] = "use NeuronAI\\Tools\\{$toolClass};";
            $implementations[] = "            {$toolClass}::make(),";
        }

        $configuration = PHP_EOL . '    /**' . PHP_EOL .
                        '     * Configure the tools for this agent.' . PHP_EOL .
                        '     */' . PHP_EOL .
                        '    public function tools(): array' . PHP_EOL .
                        '    {' . PHP_EOL .
                        '        return [' . PHP_EOL .
                        implode(PHP_EOL, $implementations) . PHP_EOL .
                        '        ];' . PHP_EOL .
                        '    }';

        return [
            'imports' => implode(PHP_EOL, $imports),
            'configuration' => $configuration
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['provider', null, InputOption::VALUE_OPTIONAL, 'The AI provider to use (openai, anthropic, gemini, ollama)'],
            ['instructions', null, InputOption::VALUE_OPTIONAL, 'Custom instructions for the agent'],
            ['tools', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of tools to include'],
            ['path', null, InputOption::VALUE_OPTIONAL, 'Custom directory path relative to app namespace'],
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the agent already exists'],
        ];
    }

    /**
     * Get the desired class name from the input.
     */
    protected function getNameInput(): string
    {
        $name = trim($this->argument('name'));
        
        // Asegurar que termine en 'Agent'
        if (!Str::endsWith($name, 'Agent')) {
            $name .= 'Agent';
        }
        
        return $name;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Mostrar información si no se proporcionan opciones
        if (!$this->hasAnyOptions()) {
            $this->info('Generating agent with empty implementations.');
            $this->comment('Use --provider, --instructions, or --tools to customize the generated agent.');
        }

        return parent::handle();
    }

    /**
     * Check if any customization options were provided.
     */
    protected function hasAnyOptions(): bool
    {
        return $this->option('provider') || 
               $this->option('instructions') || 
               $this->option('tools');
    }
} 