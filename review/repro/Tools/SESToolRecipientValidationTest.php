<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\AWS;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Ses\SesClient;
use NeuronAI\Tools\Toolkits\AWS\SESTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SESToolRecipientValidationTest extends TestCase
{
    protected int $sent = 0;

    protected function tool(): SESTool
    {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $command): Result {
            $this->sent++;
            return new Result(['MessageId' => 'msg-1']);
        });

        return new SESTool(new SesClient([
            'region' => 'eu-west-1',
            'version' => '2010-12-01',
            'credentials' => ['key' => 'AKIAEXAMPLE', 'secret' => 'secret'],
            'handler' => $handler,
        ]), 'agent@example.com');
    }

    public static function invalidCopyRecipients(): array
    {
        return [
            'cc' => [['not-an-email'], null],
            'bcc' => [null, ['not-an-email']],
            'cc header injection' => [["ada@example.com\r\nBcc: attacker@example.com"], null],
        ];
    }

    #[DataProvider('invalidCopyRecipients')]
    public function test_an_invalid_copy_recipient_is_rejected_before_sending(?array $cc, ?array $bcc): void
    {
        $result = ($this->tool())(['ada@example.com'], 'Hi', 'Body', $cc, $bcc);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->sent);
    }

    public function test_an_empty_recipient_list_is_rejected_before_sending(): void
    {
        $result = ($this->tool())([], 'Hi', 'Body');

        $this->assertFalse($result['success']);
        $this->assertSame(0, $this->sent);
    }
}
