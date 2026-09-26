<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Schema;

use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use PHPUnit\Framework\TestCase;

class DocumentFieldNameTest extends TestCase
{
    public function test_a_trailing_newline_is_not_a_portable_identifier(): void
    {
        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage("Document field \"tenant\n\" must start with a letter or underscore and contain letters, numbers, or underscores only.");

        DocumentField::string("tenant\n");
    }

    public function test_a_plain_identifier_is_accepted(): void
    {
        $this->assertSame('tenant_1', DocumentField::string('tenant_1')->getName());
    }
}
