<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\DeedPage;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Tests\Support\AssertsApiKeyConfinement;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tools\Toolkits\DeedPage\DeedPagePublishTool;
use NeuronAI\Tools\Toolkits\DeedPage\DeedPageToolkit;
use PHPUnit\Framework\TestCase;

use function json_encode;

class DeedPageToolkitTest extends TestCase
{
    use AssertsApiKeyConfinement;
    use RecordsHttpRequests;

    public function test_the_tool_sends_its_request_through_the_injected_client(): void
    {
        $client = $this->recordingClient(new Response(201, [], json_encode([
            'url' => 'https://notes-1.deed.page/', 'slug' => 'notes-1', 'version' => 'v1',
        ])));
        [$publish] = DeedPageToolkit::make('deed_tok_owner', $client)->tools();

        $publish->setInputs(['html' => '<!doctype html><p>Hi</p>'])->execute();

        $this->assertSame(['POST https://deed.page/v1/sites'], $this->sentTargets());
        $this->assertApiKeyTravelsOnlyIn('Authorization', 'deed_tok_owner', $this->sentRequests[0]['request']);
    }

    public function test_the_toolkit_offers_only_the_publish_tool(): void
    {
        $tools = DeedPageToolkit::make(httpClient: $this->recordingClient())->tools();

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(DeedPagePublishTool::class, $tools[0]);
        $this->assertSame('publish_web_page', $tools[0]->getName());
    }

    public function test_the_guidelines_name_the_tool_and_carry_no_source_indentation(): void
    {
        $toolkit = DeedPageToolkit::make(httpClient: $this->recordingClient());
        $guidelines = (string) $toolkit->guidelines();

        $this->assertStringContainsString('publish_web_page', $guidelines);
        $this->assertDoesNotMatchRegularExpression('/^[ \t]/m', $guidelines);
    }
}
