<?php

namespace NeuronAI\Tools;

enum PropertyType: string
{
    case INTEGER = 'integer';
    case STRING = 'string';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case ARRAY = 'array';
    case OBJECT = 'object';
}
