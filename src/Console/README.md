# NeuronAI Console Commands

Este directorio contiene comandos de consola para el framework NeuronAI.

## Comando make:agent

El comando `make:agent` permite generar nuevas clases de agentes AI de forma rápida y sencilla.

### Instalación

Para usar este comando en tu aplicación Laravel, registra el `ConsoleServiceProvider` en tu `config/app.php`:

```php
'providers' => [
    // ... otros providers
    NeuronAI\NeuronServiceProvider::class,
],
```

O si usas Laravel 11+, puedes registrarlo en tu `bootstrap/providers.php`:

```php
<?php

return [
    // ... otros providers
    NeuronAI\NeuronServiceProvider::class,
];
```

### Uso Básico

```bash
# Generar un agente básico
php artisan make:agent ChatAgent

# Generar un agente con proveedor específico
php artisan make:agent ChatAgent --provider=openai

# Generar un agente con instrucciones personalizadas
php artisan make:agent ChatAgent --instructions="You are a helpful customer support agent"

# Generar un agente con herramientas
php artisan make:agent ChatAgent --tools="WebSearch,EmailSender"

# Generar un agente completo
php artisan make:agent CustomerSupportAgent --provider=anthropic --instructions="You are a customer support agent" --tools="WebSearch,DatabaseQuery"
```

### Opciones Disponibles

- `--provider`: Especifica el proveedor AI (openai, anthropic, gemini, ollama)
- `--instructions`: Define las instrucciones personalizadas para el agente
- `--tools`: Lista de herramientas separadas por comas
- `--path`: Directorio personalizado (por defecto: `App\Agents`)
- `--force`: Sobrescribir archivos existentes

### Proveedores Soportados

- `openai` → `OpenAIProvider::make()`
- `anthropic` → `AnthropicProvider::make()`
- `gemini` → `GeminiProvider::make()`
- `ollama` → `OllamaProvider::make()`

### Personalización de Stubs

Puedes personalizar las plantillas publicando los stubs:

```bash
php artisan vendor:publish --tag=neuron-ai-stubs
```

Los stubs se publicarán en `stubs/neuron-ai/` donde podrás modificarlos según tus necesidades.

### Ejemplos de Salida

#### Agente Básico (sin opciones)
```php
<?php

declare(strict_types=1);

namespace App\Agents;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;

class ChatAgent extends Agent
{
    public function provider(): AIProviderInterface
    {
        // TODO: Configure your AI provider here
        // Example: return OpenAIProvider::make();
    }

    public function instructions(): string
    {
        // TODO: Define your agent instructions here
        // Example: return 'You are a helpful AI assistant.';
    }
}
```

#### Agente Completo (con todas las opciones)
```php
<?php

declare(strict_types=1);

namespace App\Agents;

use NeuronAI\Agent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAIProvider;
use NeuronAI\Tools\WebSearchTool;
use NeuronAI\Tools\DatabaseQueryTool;

class CustomerSupportAgent extends Agent
{
    public function provider(): AIProviderInterface
    {
        return OpenAIProvider::make();
    }

    public function instructions(): string
    {
        return 'You are a customer support agent';
    }

    public function tools(): array
    {
        return [
            WebSearchTool::make(),
            DatabaseQueryTool::make(),
        ];
    }
}
```

### Convenciones

- Los nombres de agentes automáticamente tendrán el sufijo "Agent" si no lo incluyes
- El directorio por defecto es `App\Agents`
- Sin opciones especificadas, se generan implementaciones vacías con comentarios TODO
