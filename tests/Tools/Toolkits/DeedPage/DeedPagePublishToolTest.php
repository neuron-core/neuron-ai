<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\DeedPage;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\DeedPage\DeedPagePublishTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;
use function str_repeat;

class DeedPagePublishToolTest extends TestCase
{
    use RecordsHttpRequests;
    use ToolErrorAssertions;

    public function test_posts_the_html_as_index_html_and_returns_the_url(): void
    {
        $tool = new DeedPagePublishTool('deed_tok_owner', $this->recordingClient($this->published('report-q3')));

        $tool->setInputs(['html' => '<!doctype html><title>Q3</title>', 'slug' => 'report-q3'])->execute();

        $this->assertSame(['POST https://deed.page/v1/sites'], $this->sentTargets());
        $request = $this->sentRequests[0]['request'];
        $this->assertSame('Bearer deed_tok_owner', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('neuron-ai', $request->getHeaderLine('X-Client'));
        $this->assertSame([
            'files' => ['index.html' => '<!doctype html><title>Q3</title>'],
            'slug' => 'report-q3',
        ], $this->sentBody());
        $this->assertSame(
            ['url' => 'https://report-q3.deed.page/', 'slug' => 'report-q3', 'version' => 'v1'],
            json_decode((string) $tool->getResult(), true)
        );
    }

    public function test_without_a_slug_the_service_picks_one(): void
    {
        $tool = new DeedPagePublishTool('deed_tok_owner', $this->recordingClient($this->published('k3x9q2')));

        $tool('<!doctype html><p>Hi</p>');

        $this->assertSame(['files' => ['index.html' => '<!doctype html><p>Hi</p>']], $this->sentBody());
    }

    public function test_the_first_publish_without_a_token_keeps_the_new_token_away_from_the_model(): void
    {
        $tool = new DeedPagePublishTool(httpClient: $this->recordingClient(
            $this->published('notes-1', ['token' => 'deed_tok_minted', 'token_note' => 'Shown once.']),
            $this->published('notes-1', ['version' => 'v2']),
        ));

        $first = $tool('<!doctype html><p>v1</p>', 'notes-1');
        $second = $tool('<!doctype html><p>v2</p>', 'notes-1');

        $this->assertSame('', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('deed_tok_minted', (string) json_encode($first));
        $this->assertSame('deed_tok_minted', $tool->getToken());
        // The next publish in the run updates the same page as its owner
        $this->assertSame('Bearer deed_tok_minted', $this->sentRequests[1]['request']->getHeaderLine('Authorization'));
        $this->assertSame('v2', $second['version']);
    }

    public function test_a_configured_token_is_never_replaced(): void
    {
        $tool = new DeedPagePublishTool('deed_tok_owner', $this->recordingClient(
            $this->published('notes-1', ['token' => 'deed_tok_other'])
        ));

        $tool('<!doctype html><p>Hi</p>');

        $this->assertSame('deed_tok_owner', $tool->getToken());
    }

    public function test_a_taken_slug_is_an_error_for_the_model_with_the_suggestion(): void
    {
        $tool = new DeedPagePublishTool('deed_tok_owner', $this->recordingClient(new Response(409, [], json_encode([
            'code' => 'slug_taken',
            'message' => 'That slug belongs to another owner.',
            'suggestion' => 'report-q3-2',
        ]))));

        $this->assertToolError(
            'deed.page did not publish the page (slug_taken): That slug belongs to another owner. Suggestion: report-q3-2',
            $tool('<!doctype html><p>Hi</p>', 'report-q3')
        );
    }

    public function test_an_oversized_first_publish_keeps_the_token_out_of_the_error(): void
    {
        $tool = new DeedPagePublishTool(httpClient: $this->recordingClient(new Response(413, [], json_encode([
            'code' => 'quota_bytes',
            'message' => 'Site bigger than your plan allows.',
            'token' => 'deed_tok_minted',
        ]))));

        $result = $tool('<!doctype html><p>Big</p>');

        $this->assertToolError('deed.page did not publish the page (quota_bytes): Site bigger than your plan allows.', $result);
        $this->assertSame('deed_tok_minted', $tool->getToken());
    }

    public function test_a_rejected_token_raises_an_http_exception_without_the_token(): void
    {
        $tool = new DeedPagePublishTool('deed_tok_SECRET', $this->recordingClient(
            new Response(401, [], '{"code":"unauthorized","message":"Missing or unknown token."}')
        ));

        try {
            $tool('<!doctype html><p>Hi</p>');
            $this->fail('Expected an HttpException for a 401 response.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringNotContainsString('deed_tok_SECRET', $exception->getMessage());
        }
    }

    public function test_an_empty_page_is_rejected_before_any_request(): void
    {
        $tool = new DeedPagePublishTool('deed_tok_owner', $this->recordingClient());

        $this->assertToolError('The page is empty: pass the HTML document to publish.', $tool("  \n"));
        $this->assertSame([], $this->sentRequests);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSlugs(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['Report'],
            'underscore' => ['my_page'],
            'leading hyphen' => ['-page'],
            'trailing hyphen' => ['page-'],
            'too long' => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('invalidSlugs')]
    public function test_an_invalid_slug_is_rejected_before_any_request(string $slug): void
    {
        $tool = new DeedPagePublishTool('deed_tok_owner', $this->recordingClient());

        $this->assertToolError(
            'Invalid slug: use 1 to 63 lowercase letters, digits and hyphens, not starting or ending with a hyphen.',
            $tool('<!doctype html><p>Hi</p>', $slug)
        );
        $this->assertSame([], $this->sentRequests);
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function published(string $slug, array $extra = []): Response
    {
        return new Response(201, [], json_encode([
            'ok' => true,
            'url' => "https://{$slug}.deed.page/",
            'slug' => $slug,
            'version' => 'v1',
            ...$extra,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(): array
    {
        return json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
    }
}
