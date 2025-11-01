<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use NeuronAI\StaticConstructor;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class StaticConstructorTest extends TestCase
{
    public function test_make_with_positional_parameters(): void
    {
        // Test that existing positional parameters still work
        $tool = Tool::make('test_tool', 'Test description');

        $this->assertEquals('test_tool', $tool->getName());
        $this->assertEquals('Test description', $tool->getDescription());
    }

    public function test_make_with_associative_array(): void
    {
        // This is the main use case from MCP connector
        $namedArgs = [
            'name' => 'test_tool',
            'description' => 'Test description'
        ];

        $tool = Tool::make($namedArgs);

        $this->assertEquals('test_tool', $tool->getName());
        $this->assertEquals('Test description', $tool->getDescription());
    }

    public function test_make_with_associative_array_partial(): void
    {
        // Test that associative array with optional values work
        $namedArgs = [
            'name' => 'test_tool'
            // description is optional
        ];

        $tool = Tool::make($namedArgs);

        $this->assertEquals('test_tool', $tool->getName());
        $this->assertEquals(null, $tool->getDescription());
    }

    public function test_make_with_associative_array_reordered(): void
    {
        // Test that associative array parameters work in any order
        $namedArgs = [
            'description' => 'Test description',
            'name' => 'test_tool'
        ];

        $tool = Tool::make($namedArgs);

        $this->assertEquals('test_tool', $tool->getName());
        $this->assertEquals('Test description', $tool->getDescription());
    }

    public function test_make_with_associative_array_missing_required(): void
    {
        // Test that missing required parameters throw an exception
        $this->expectException(\ArgumentCountError::class);
        $this->expectExceptionMessage('Missing required parameter: name');

        $namedArgs = [
            'description' => 'Test description'
            // name is required but missing
        ];

        Tool::make($namedArgs);
    }

    public function test_make_with_positional_parameters_backward_compatibility(): void
    {
        // Test that the old way still works (backward compatibility)
        $tool = Tool::make('test_tool', 'Test description', []);

        $this->assertEquals('test_tool', $tool->getName());
        $this->assertEquals('Test description', $tool->getDescription());
    }

    public function test_make_with_sequential_array(): void
    {
        // Test that sequential arrays are treated as positional arguments
        $positionalArgs = ['test_tool', 'Test description'];

        $tool = Tool::make($positionalArgs);

        $this->assertEquals('test_tool', $tool->getName());
        $this->assertEquals('Test description', $tool->getDescription());
    }

    public function test_make_with_empty_array(): void
    {
        // Test that empty array triggers missing required parameter error
        $this->expectException(\ArgumentCountError::class);
        $this->expectExceptionMessage('Missing required parameter: name');

        Tool::make([]);
    }

    public function test_make_with_mcp_use_case(): void
    {
        // Test the exact use case from MCP connector that was failing
        $mcpToolData = [
            'name' => 'list_projects',
            'description' => 'List all projects with details including task counts and progress'
        ];

        $tool = Tool::make($mcpToolData);

        $this->assertEquals('list_projects', $tool->getName());
        $this->assertEquals('List all projects with details including task counts and progress', $tool->getDescription());
    }

    public function test_make_with_direct_named_parameters(): void
    {
        // Test the actual failing case: direct named parameters in function call
        // This is what was actually causing the "Unknown named parameter $name" error
        $tool = Tool::make(
            name: 'test_direct_named',
            description: 'Test direct named parameters'
        );

        $this->assertEquals('test_direct_named', $tool->getName());
        $this->assertEquals('Test direct named parameters', $tool->getDescription());
    }
}