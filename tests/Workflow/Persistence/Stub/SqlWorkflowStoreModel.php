<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use Illuminate\Database\Eloquent\Model;

class SqlWorkflowStoreModel extends Model
{
    public static string $storeTable;

    public function getTable(): string
    {
        return self::$storeTable;
    }
}
