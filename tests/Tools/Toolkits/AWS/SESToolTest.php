<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\AWS;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Ses\Exception\SesException;
use Aws\Ses\SesClient;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\Toolkits\AWS\SESTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;

class SESToolTest extends TestCase
{
    protected MockHandler $ses;

    /** @var list<array<string, mixed>> */
    protected array $sentCommands = [];

    protected SESTool $tool;

    protected function setUp(): void
    {
        $this->ses = new MockHandler();
        $this->tool = new SESTool(new SesClient([
            'region' => 'eu-west-1',
            'version' => '2010-12-01',
            'credentials' => ['key' => 'AKIAEXAMPLE', 'secret' => 'secret-access-key'],
            'handler' => $this->ses,
        ]), 'agent@example.com');
    }

    public function test_sends_an_html_email_with_a_plain_text_alternative(): void
    {
        $this->queueResult(['MessageId' => 'msg-1', '@metadata' => ['requestId' => 'req-1']]);

        $result = ($this->tool)(['ada@example.com'], 'Report', '<h1>Hello</h1><p>All <b>green</b>.</p>');

        $this->assertSame([
            'Source' => 'agent@example.com',
            'Destination' => ['ToAddresses' => ['ada@example.com']],
            'Message' => [
                'Subject' => ['Data' => 'Report', 'Charset' => 'UTF-8'],
                'Body' => [
                    'Html' => ['Data' => '<h1>Hello</h1><p>All <b>green</b>.</p>', 'Charset' => 'UTF-8'],
                    'Text' => ['Data' => 'HelloAll green.', 'Charset' => 'UTF-8'],
                ],
            ],
        ], $this->sentCommands[0]);
        $this->assertSame([
            'success' => true,
            'message_id' => 'msg-1',
            'status' => 'sent',
            'recipients_count' => 1,
            'aws_request_id' => 'req-1',
        ], $result);
    }

    public function test_cc_and_bcc_are_added_only_when_present(): void
    {
        $this->queueResult(['MessageId' => 'msg-1']);
        $this->queueResult(['MessageId' => 'msg-2']);

        ($this->tool)(['ada@example.com', 'alan@example.com'], 'Hi', 'Body', ['grace@example.com'], ['audit@example.com']);
        ($this->tool)(['ada@example.com'], 'Hi', 'Body', [], []);

        $this->assertSame([
            'ToAddresses' => ['ada@example.com', 'alan@example.com'],
            'CcAddresses' => ['grace@example.com'],
            'BccAddresses' => ['audit@example.com'],
        ], $this->sentCommands[0]['Destination']);
        $this->assertSame(['ToAddresses' => ['ada@example.com']], $this->sentCommands[1]['Destination']);
    }

    public function test_the_recipient_count_ignores_cc_and_bcc(): void
    {
        $this->queueResult(['MessageId' => 'msg-1']);

        $result = ($this->tool)(['ada@example.com', 'alan@example.com'], 'Hi', 'Body', ['grace@example.com'], ['audit@example.com']);

        $this->assertSame(2, $result['recipients_count']);
        $this->assertNull($result['aws_request_id']);
    }

    public function test_unicode_subject_and_body_are_sent_as_utf8(): void
    {
        $this->queueResult(['MessageId' => 'msg-1']);

        ($this->tool)(['ada@example.com'], 'Café ☕ – résumé', '<p>Grüße 你好</p>');

        $this->assertSame('Café ☕ – résumé', $this->sentCommands[0]['Message']['Subject']['Data']);
        $this->assertSame('Grüße 你好', $this->sentCommands[0]['Message']['Body']['Text']['Data']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidRecipients(): array
    {
        return [
            'missing domain' => ['ada@'],
            'plain name' => ['Ada Lovelace'],
            'display name' => ['Ada <ada@example.com>'],
            'header injection' => ["ada@example.com\r\nBcc: attacker@example.com"],
            'two addresses in one' => ['ada@example.com,attacker@example.com'],
        ];
    }

    #[DataProvider('invalidRecipients')]
    public function test_an_invalid_recipient_fails_without_sending(string $recipient): void
    {
        $result = ($this->tool)(['ada@example.com', $recipient], 'Hi', 'Body');

        $this->assertSame([
            'success' => false,
            'error' => 'Invalid email address: '.$recipient.'.',
            'error_type' => ToolException::class,
            'status' => 'failed',
        ], $result);
        $this->assertSame([], $this->sentCommands);
    }

    public function test_an_ses_rejection_is_reported_to_the_model_as_a_failure(): void
    {
        $this->ses->append(fn (CommandInterface $command): SesException => new SesException(
            'Email address is not verified.',
            $command,
            ['code' => 'MessageRejected'],
        ));

        $result = ($this->tool)(['ada@example.com'], 'Hi', 'Body');

        $this->assertSame([
            'success' => false,
            'error' => 'Email address is not verified.',
            'error_type' => SesException::class,
            'status' => 'failed',
        ], $result);
    }

    public function test_a_failure_never_exposes_the_aws_credentials(): void
    {
        $this->ses->append(fn (CommandInterface $command): SesException => new SesException('Signature mismatch', $command));

        $this->tool->setInputs(['to' => ['ada@example.com'], 'subject' => 'Hi', 'body' => 'Body'])->execute();

        $result = (string) $this->tool->getResult();
        $this->assertSame('failed', json_decode($result, true)['status']);
        $this->assertStringNotContainsString('secret-access-key', $result);
        $this->assertStringNotContainsString('AKIAEXAMPLE', $result);
    }

    public function test_a_single_address_instead_of_a_list_is_returned_to_the_model(): void
    {
        $this->tool->setInputs(['to' => 'ada@example.com', 'subject' => 'Hi', 'body' => 'Body'])->execute();

        $this->assertSame('Parameter "to" must be of type array, string given.', (string) $this->tool->getResult());
        $this->assertSame([], $this->sentCommands);
    }

    public function test_the_schema_requires_at_least_one_recipient(): void
    {
        $to = $this->tool->getProperties()[0];

        $this->assertInstanceOf(ArrayProperty::class, $to);
        $this->assertSame(['to', 'subject', 'body'], $this->tool->getRequiredProperties());
        $this->assertSame(1, $to->getJsonSchema()['minItems']);
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function queueResult(array $result): void
    {
        $this->ses->append(function (CommandInterface $command) use ($result): Result {
            $params = $command->toArray();
            unset($params['@http'], $params['@context']);
            $this->sentCommands[] = $params;

            return new Result($result);
        });
    }
}
