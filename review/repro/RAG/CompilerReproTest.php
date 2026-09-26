<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Compilers\MariaDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use PHPUnit\Framework\TestCase;

class CompilerReproTest extends TestCase
{
    public function test_mariadb_refuses_a_field_name_with_a_trailing_newline(): void
    {
        $compiler = new MariaDBFilterCompiler();

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('is not a valid identifier for a SQL filter');

        $compiler->compile(Filter::eq("tenant\n", 'acme'));
    }

    public function test_document_field_refuses_a_name_with_a_trailing_newline(): void
    {
        $this->expectException(DocumentSchemaException::class);

        DocumentField::string("tenant\n");
    }
}
