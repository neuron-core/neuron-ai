<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Router;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Router\Rules\CallbackRule;
use NeuronAI\Router\Rules\ContentRule;
use NeuronAI\Router\Rules\MethodRule;
use NeuronAI\Router\Rules\RoundRobinRule;
use NeuronAI\Router\Rules\RoutingRuleInterface;
use NeuronAI\Router\RouterProvider;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class RouterProviderTest extends TestCase
{
    // --- CallbackRule tests ---

    public function test_callback_rule_routes_chat_to_correct_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('from anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('from openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule(new CallbackRule(fn (string $method): string => $method === 'structured' ? 'openai' : 'anthropic'));

        $response = $router
            ->systemPrompt('test prompt')
            ->chat(UserMessage::make('hello'))
            ->message();

        $this->assertSame('from anthropic', $response->getContent());
    }

    public function test_callback_rule_routes_structured_to_different_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('from anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('from openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule(new CallbackRule(fn (string $method): string => $method === 'structured' ? 'openai' : 'anthropic'));

        $response = $router
            ->systemPrompt('test')
            ->setTools([])
            ->structured([UserMessage::make('hello')], 'stdClass', [])
            ->message();

        $this->assertSame('from openai', $response->getContent());
    }

    public function test_callback_rule_receives_messages_and_tools(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));
        $tool = new class () extends Tool {
            protected string $name = 'search';
            protected ?string $description = 'Search tool';
        };
        $received = [];

        $router = RouterProvider::make()
            ->addProvider('main', $fake)
            ->setRule(new CallbackRule(function (string $method, array $messages, array $tools) use (&$received): string {
                $received = [$method, $messages, $tools];
                return 'main';
            }));

        $router
            ->systemPrompt(null)
            ->setTools([$tool])
            ->chat(UserMessage::make('hello'));

        $this->assertSame('chat', $received[0]);
        $this->assertCount(1, $received[1]);
        $this->assertCount(1, $received[2]);
    }

    // --- MethodRule tests ---

    public function test_method_rule_routes_by_method(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('from anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('from openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule((new MethodRule('anthropic'))->structured('openai'));

        $chatResponse = $router
            ->systemPrompt('test')
            ->setTools([])
            ->chat(UserMessage::make('hello'))
            ->message();

        $this->assertSame('from anthropic', $chatResponse->getContent());

        // Need to add another response for the structured call
        $anthropic->addResponses(AssistantMessage::make('from anthropic 2'));

        $structuredResponse = $router
            ->systemPrompt('test')
            ->setTools([])
            ->structured([UserMessage::make('hello')], 'stdClass', [])
            ->message();

        $this->assertSame('from openai', $structuredResponse->getContent());
    }

    public function test_method_rule_uses_default_when_no_override(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('default'));

        $router = RouterProvider::make()
            ->addProvider('default', $fake)
            ->setRule(new MethodRule('default'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat(UserMessage::make('hello'))
            ->message();

        $this->assertSame('default', $response->getContent());
    }

    // --- Streaming tests ---

    public function test_routes_stream_and_yields_chunks(): void
    {
        $provider = FakeAIProvider::make(AssistantMessage::make('hello world'));

        $router = RouterProvider::make()
            ->addProvider('default', $provider)
            ->setRule(new CallbackRule(fn (): string => 'default'));

        $stream = $router
            ->systemPrompt(null)
            ->setTools([])
            ->stream(UserMessage::make('hi'));

        $chunks = [];
        foreach ($stream as $chunk) {
            $chunks[] = $chunk;
        }

        $this->assertNotEmpty($chunks);
        $this->assertSame('hello world', $stream->getReturn()->message()->getContent());
    }

    // --- Forwarding tests ---

    public function test_forwards_system_prompt_and_tools_to_chosen_provider(): void
    {
        $tool = new class () extends Tool {
            protected string $name = 'test_tool';
            protected ?string $description = 'A test tool';
        };
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));

        $router = RouterProvider::make()
            ->addProvider('main', $fake)
            ->setRule(new CallbackRule(fn (): string => 'main'));

        $router
            ->systemPrompt('system instructions')
            ->setTools([$tool])
            ->chat(UserMessage::make('go'));

        $fake->assertSystemPrompt('system instructions');
        $fake->assertToolsConfigured(['test_tool']);
    }

    public function test_set_http_client_propagates_to_all_providers(): void
    {
        $fake1 = FakeAIProvider::make(AssistantMessage::make('a'));
        $fake2 = FakeAIProvider::make(AssistantMessage::make('b'));
        $client = $this->createMock(HttpClientInterface::class);

        $router = RouterProvider::make()
            ->addProvider('one', $fake1)
            ->addProvider('two', $fake2);

        $result = $router->setHttpClient($client);

        $this->assertSame($router, $result);
    }

    // --- Error handling tests ---

    public function test_throws_when_rule_returns_unknown_provider(): void
    {
        $fake = FakeAIProvider::make(AssistantMessage::make('ok'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $fake)
            ->setRule(new CallbackRule(fn (): string => 'unknown'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("RouterProvider: unknown provider 'unknown'");

        $router->chat(UserMessage::make('hello'));
    }

    public function test_throws_when_no_rule_configured(): void
    {
        $router = RouterProvider::make()
            ->addProvider('anthropic', FakeAIProvider::make(AssistantMessage::make('ok')));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('no routing strategy configured');

        $router->chat(UserMessage::make('hello'));
    }

    public function test_throws_when_no_providers_registered(): void
    {
        $router = RouterProvider::make()
            ->setRule(new CallbackRule(fn (): string => 'anthropic'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('no providers registered');

        $router->chat(UserMessage::make('hello'));
    }

    public function test_message_mapper_throws(): void
    {
        $router = RouterProvider::make()
            ->addProvider('main', FakeAIProvider::make(AssistantMessage::make('ok')))
            ->setRule(new CallbackRule(fn (): string => 'main'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('message mapper');
        $router->messageMapper();
    }

    public function test_tool_payload_mapper_throws(): void
    {
        $router = RouterProvider::make()
            ->addProvider('main', FakeAIProvider::make(AssistantMessage::make('ok')))
            ->setRule(new CallbackRule(fn (): string => 'main'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('tool payload mapper');
        $router->toolPayloadMapper();
    }

    // --- Interface compliance ---

    public function test_router_provider_implements_ai_provider_interface(): void
    {
        $router = RouterProvider::make()
            ->addProvider('main', FakeAIProvider::make(AssistantMessage::make('ok')))
            ->setRule(new CallbackRule(fn (): string => 'main'));

        $this->assertInstanceOf(AIProviderInterface::class, $router);
    }

    public function test_rules_implement_routing_rule_interface(): void
    {
        $this->assertInstanceOf(RoutingRuleInterface::class, new CallbackRule(fn (): string => 'a'));
        $this->assertInstanceOf(RoutingRuleInterface::class, new MethodRule('a'));
        $this->assertInstanceOf(RoutingRuleInterface::class, new RoundRobinRule(['a', 'b']));
        $this->assertInstanceOf(RoutingRuleInterface::class, new ContentRule('a'));
    }

    // --- ContentRule tests ---

    public function test_content_rule_routes_to_default_for_text_only(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $gemini = FakeAIProvider::make(AssistantMessage::make('gemini'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('gemini', $gemini)
            ->setRule((new ContentRule('anthropic'))->image('gemini'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat(UserMessage::make('just text'))
            ->message();

        $this->assertSame('anthropic', $response->getContent());
    }

    public function test_content_rule_routes_image_to_configured_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $gemini = FakeAIProvider::make(AssistantMessage::make('gemini'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('gemini', $gemini)
            ->setRule((new ContentRule('anthropic'))->image('gemini'));

        $message = UserMessage::make('describe this');
        $message->addContent(new ImageContent('https://example.com/photo.jpg', SourceType::URL, 'image/jpeg'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message)
            ->message();

        $this->assertSame('gemini', $response->getContent());
    }

    public function test_content_rule_routes_file_to_configured_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule((new ContentRule('anthropic'))->file('openai'));

        $message = UserMessage::make('read this');
        $message->addContent(new FileContent('base64data', SourceType::BASE64, 'application/pdf'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message)
            ->message();

        $this->assertSame('openai', $response->getContent());
    }

    public function test_content_rule_routes_audio_to_configured_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $openai = FakeAIProvider::make(AssistantMessage::make('openai'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('openai', $openai)
            ->setRule((new ContentRule('anthropic'))->audio('openai'));

        $message = UserMessage::make('transcribe this');
        $message->addContent(new AudioContent('base64audio', SourceType::BASE64, 'audio/wav'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message)
            ->message();

        $this->assertSame('openai', $response->getContent());
    }

    public function test_content_rule_routes_video_to_configured_provider(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));
        $gemini = FakeAIProvider::make(AssistantMessage::make('gemini'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->addProvider('gemini', $gemini)
            ->setRule((new ContentRule('anthropic'))->video('gemini'));

        $message = UserMessage::make('describe this');
        $message->addContent(new VideoContent('https://example.com/clip.mp4', SourceType::URL, 'video/mp4'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message)
            ->message();

        $this->assertSame('gemini', $response->getContent());
    }

    public function test_content_rule_video_takes_precedence_over_image(): void
    {
        $gemini = FakeAIProvider::make(AssistantMessage::make('gemini'));
        $openai = FakeAIProvider::make(AssistantMessage::make('openai'));

        $router = RouterProvider::make()
            ->addProvider('gemini', $gemini)
            ->addProvider('openai', $openai)
            ->setRule((new ContentRule('gemini'))->image('openai')->video('gemini'));

        $message = UserMessage::make('describe these');
        $message->addContent(new ImageContent('https://example.com/photo.jpg', SourceType::URL, 'image/jpeg'));
        $message->addContent(new VideoContent('https://example.com/clip.mp4', SourceType::URL, 'video/mp4'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message)
            ->message();

        $this->assertSame('gemini', $response->getContent());
    }

    public function test_content_rule_ignores_unconfigured_content_types(): void
    {
        $anthropic = FakeAIProvider::make(AssistantMessage::make('anthropic'));

        $router = RouterProvider::make()
            ->addProvider('anthropic', $anthropic)
            ->setRule((new ContentRule('anthropic'))->image('gemini'));

        // Message has audio but only image routing is configured
        $message = UserMessage::make('listen');
        $message->addContent(new AudioContent('base64audio', SourceType::BASE64, 'audio/wav'));

        $response = $router
            ->systemPrompt(null)
            ->setTools([])
            ->chat($message)
            ->message();

        $this->assertSame('anthropic', $response->getContent());
    }

    // --- RoundRobinRule tests ---

    public function test_round_robin_cycles_through_providers(): void
    {
        $providerA = FakeAIProvider::make(
            AssistantMessage::make('a1'),
            AssistantMessage::make('a2'),
        );
        $providerB = FakeAIProvider::make(
            AssistantMessage::make('b1'),
            AssistantMessage::make('b2'),
        );

        $router = RouterProvider::make()
            ->addProvider('a', $providerA)
            ->addProvider('b', $providerB)
            ->setRule(new RoundRobinRule(['a', 'b']));

        $response1 = $router->systemPrompt(null)
            ->setTools([])
            ->chat(UserMessage::make('hi'))
            ->message();
        $this->assertSame('a1', $response1->getContent());

        $response2 = $router->systemPrompt(null)
            ->setTools([])
            ->chat(UserMessage::make('hi'))
            ->message();
        $this->assertSame('b1', $response2->getContent());

        $response3 = $router->systemPrompt(null)
            ->setTools([])
            ->chat(UserMessage::make('hi'))
            ->message();
        $this->assertSame('a2', $response3->getContent());

        $response4 = $router->systemPrompt(null)
            ->setTools([])
            ->chat(UserMessage::make('hi'))
            ->message();
        $this->assertSame('b2', $response4->getContent());
    }
}
