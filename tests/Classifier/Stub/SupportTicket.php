<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Classifier\Stub;

use NeuronAI\Classifier\Boolean;
use NeuronAI\Classifier\Choice;
use NeuronAI\Classifier\ClassificationRequest;
use NeuronAI\Classifier\Score;

/**
 * One request asking every question type about a support ticket, shared by the provider tests.
 */
class SupportTicket
{
    public static function request(): ClassificationRequest
    {
        return new ClassificationRequest(
            input: ['ticket' => 'My parcel never arrived, refund me.'],
            questions: [
                'department' => new Choice('Which department should handle this ticket?', [
                    'billing' => 'Charges and payment problems.',
                    'shipping' => 'Delivery and tracking problems.',
                    'other' => 'Requests outside these departments.',
                ]),
                'severity' => new Score('How severe is the reported problem?', [
                    'Cosmetic issue.',
                    'Workaround available.',
                    'Completely blocked.',
                ]),
                'refund' => new Boolean('Does the customer request a refund?'),
            ],
        );
    }
}
