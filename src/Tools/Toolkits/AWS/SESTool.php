<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\AWS;

use Aws\Ses\SesClient;
use NeuronAI\Exceptions\ArrayPropertyException;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Exception;

use function count;
use function filter_var;
use function strip_tags;

use const FILTER_VALIDATE_EMAIL;

/**
 * @method static make(SesClient $sesClient, string $fromEmail)
 */
class SESTool extends Tool
{
    public function __construct(
        protected SesClient $sesClient,
        protected string $fromEmail
    ) {
        parent::__construct(
            'send_email',
            <<<DESC
Send an email message to one or more recipients. Use this tool when you need to communicate with users via email,
send notifications, confirmations, reports, or any other email-based communication. The tool handles proper email
formatting, delivery, and basic error handling automatically.
DESC
        );
    }

    /**
     * @throws ArrayPropertyException
     */
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'to',
                description: 'Array of recipient email addresses. Each email must be a valid email format (e.g., ["user@example.com", "admin@company.com"]). Required - at least one recipient must be specified.',
                required: true,
                minItems: 1,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'The email subject line. Should be clear, concise, and descriptive of the email content. This appears in the recipient\'s inbox list.',
                required: true,
            ),
            new ToolProperty(
                name: 'body',
                type: PropertyType::STRING,
                description: 'The main email message content. Can include plain text or HTML formatting. This is the primary message you want to communicate to the recipient(s).',
                required: true,
            ),
            new ArrayProperty(
                name: 'cc',
                description: 'Optional array of email addresses to carbon copy (CC). These recipients will receive the email and all recipients can see these CC addresses.',
            ),
            new ArrayProperty(
                name: 'bcc',
                description: 'Optional array of email addresses to blind carbon copy (BCC). These recipients will receive the email but other recipients cannot see these addresses. Useful for privacy or internal notifications.',
            ),
        ];
    }

    public function __invoke(
        array   $to,
        string  $subject,
        string  $body,
        ?array  $cc = null,
        ?array  $bcc = null,
        ?string $reply_to = null,
    ): array {
        try {
            $this->validateRecipients($to);

            $result = $this->sesClient->sendEmail([
                'Source' => $this->fromEmail,
                'Destination' => $this->buildDestinations($to, $cc, $bcc),
                'Message' => $this->buildMessage($subject, $body),
            ]);

            return [
                'success' => true,
                'message_id' => $result['MessageId'] ?? null,
                'status' => 'sent',
                'recipients_count' => count($to),
                'aws_request_id' => $result['@metadata']['requestId'] ?? null
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_type' => $e::class,
                'status' => 'failed'
            ];
        }
    }

    /**
     * @param array<string> $to
     * @throws ToolException
     */
    protected function validateRecipients(array $to): void
    {
        foreach ($to as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new ToolException('Invalid email address: ' . $recipient . '.');
            }
        }
    }

    protected function buildDestinations(array $to, ?array $cc = null, ?array $bcc = null): array
    {
        $destinations = [
            'ToAddresses' => $to,
        ];

        if ($cc !== null && $cc !== []) {
            $destinations['CcAddresses'] = $cc;
        }

        if ($bcc !== null && $bcc !== []) {
            $destinations['BccAddresses'] = $bcc;
        }

        return $destinations;
    }

    protected function buildMessage(string $subject, string $body): array
    {
        return [
            'Subject' => [
                'Data' => $subject,
                'Charset' => 'UTF-8'
            ],
            'Body' => [
                'Html' => [
                    'Data' => $body,
                    'Charset' => 'UTF-8'
                ],
                'Text' => [
                    'Data' => strip_tags($body),
                    'Charset' => 'UTF-8'
                ]
            ]
        ];
    }
}
