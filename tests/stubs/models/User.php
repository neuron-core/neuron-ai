<?php

namespace NeuronAI\Tests\stubs\models;

use NeuronAI\StructuredOutput\SchemaProperty;

class User
{
    #[SchemaProperty(description: 'The lastname of the user', required: false)]
    public string $lastname;

    #[SchemaProperty(description: 'The firstname of the user', required: false)]
    public string $firstname;

    #[SchemaProperty(description: 'The email of the user', required: false)]
    public string $email;

    #[SchemaProperty(description: 'The language of the user', required: true)]
    public string $language;
}
