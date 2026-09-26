<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\Jina\JinaToolkit;
use NeuronAI\Tools\Toolkits\Supadata\SupadataYouTubeToolkit;
use NeuronAI\Tools\Toolkits\Tavily\TavilyToolkit;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\Toolkits\Zep\ZepLongTermMemoryToolkit;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function implode;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Toolkit keys authenticate the toolkit's own service. What the model is told
 * about the tools (definitions, schemas, guidelines) travels to the AI vendor
 * and into the model's context, so it must never carry them.
 */
class ToolkitCredentialsTrustBoundarySecurityTest extends TestCase
{
    use RecordsHttpRequests;

    protected const PROVIDER_KEY = 'sk-provider-SECRET-0a1b';

    /** @var array<string, string> */
    protected const TOOLKIT_KEYS = [
        TavilyToolkit::class => 'tvly-SECRET-2c3d',
        JinaToolkit::class => 'jina-SECRET-4e5f',
        ZepLongTermMemoryToolkit::class => 'zep-SECRET-6a7b',
        SupadataYouTubeToolkit::class => 'supadata-SECRET-8c9d',
    ];

    /**
     * @return ToolkitInterface[]
     */
    protected function toolkits(): array
    {
        return [
            new TavilyToolkit(self::TOOLKIT_KEYS[TavilyToolkit::class]),
            new JinaToolkit(self::TOOLKIT_KEYS[JinaToolkit::class]),
            new ZepLongTermMemoryToolkit(self::TOOLKIT_KEYS[ZepLongTermMemoryToolkit::class], 'user-1'),
            new SupadataYouTubeToolkit(self::TOOLKIT_KEYS[SupadataYouTubeToolkit::class]),
        ];
    }

    public function test_tool_definitions_sent_to_the_model_never_carry_toolkit_keys(): void
    {
        $client = $this->recordingClient(new Response(200, body: json_encode([
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Hello']]],
        ], JSON_THROW_ON_ERROR)));
        $toolkits = $this->toolkits();

        Agent::make()
            ->setAiProvider(new OpenAI(self::PROVIDER_KEY, 'model', httpClient: $client))
            ->addTool($toolkits)
            ->chat(new UserMessage('Hi'));

        $request = $this->sentRequests[0]['request'];
        $body = (string) $request->getBody();
        $offered = array_column(array_column(json_decode($body, true, flags: JSON_THROW_ON_ERROR)['tools'], 'function'), 'name');

        $expected = [];
        foreach ($toolkits as $toolkit) {
            foreach ($toolkit->tools() as $tool) {
                $expected[] = $tool->getName();
            }
        }
        $this->assertSame($expected, $offered, 'Every toolkit tool is offered to the model.');
        $this->assertStringContainsString('<TOOLS-GUIDELINES>', $body);
        $this->assertSame('Bearer '.self::PROVIDER_KEY, $request->getHeaderLine('Authorization'));

        $headers = implode("\n", array_map(static fn (array $values): string => implode(', ', $values), $request->getHeaders()));
        foreach (self::TOOLKIT_KEYS as $toolkit => $key) {
            $this->assertStringNotContainsString($key, $body, "The {$toolkit} key reaches the AI vendor.");
            $this->assertStringNotContainsString($key, $headers, "The {$toolkit} key reaches the AI vendor's headers.");
            $this->assertStringNotContainsString($key, (string) $request->getUri());
        }
    }

    public function test_the_json_form_of_a_keyed_tool_carries_no_key(): void
    {
        foreach ($this->toolkits() as $toolkit) {
            $key = self::TOOLKIT_KEYS[$toolkit::class];
            foreach ($toolkit->tools() as $tool) {
                $this->assertInstanceOf(ToolInterface::class, $tool);
                $json = json_encode($tool, JSON_THROW_ON_ERROR);
                $this->assertStringContainsString('"name":'.json_encode($tool->getName(), JSON_THROW_ON_ERROR), $json);
                $this->assertStringNotContainsString($key, $json, "{$tool->getName()} exposes its key in its JSON form.");
            }
        }
    }
}
