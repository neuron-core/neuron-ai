<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\TodoPlanning;

use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $guidelines = self::GUIDELINES)
 */
class TodoPlanningToolkit extends AbstractToolkit
{
    protected const GUIDELINES = <<<'TEXT'
        You have access to the `write_todos` tool to help you manage and plan complex objectives.
        Use this tool for complex objectives to ensure that you are tracking each necessary step and giving the user visibility into your progress.
        This tool is very helpful for planning complex objectives, and for breaking down these larger complex objectives into smaller steps.

        It is critical that you mark todos as completed as soon as you are done with a step. Do not batch up multiple steps before marking them as completed.
        For simple objectives that only require a few steps, it is better to just complete the objective directly and NOT use this tool.
        Writing todos takes time and tokens, use it when it is helpful for managing complex many-step problems! But not for simple few-step requests.

        ## Important To-Do List Usage Notes to Remember
        - The `write_todos` tool should never be called multiple times in parallel.
        - Don't be afraid to revise the To-Do list as you go. New information may reveal new tasks that need to be done, or old tasks that are irrelevant.
        TEXT;

    public function __construct(protected string $guidelines = self::GUIDELINES)
    {
    }

    public function guidelines(): ?string
    {
        return $this->guidelines;
    }

    public function provide(): array
    {
        return [WriteTodosTool::make()];
    }
}
