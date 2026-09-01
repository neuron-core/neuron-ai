<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use Illuminate\Database\Eloquent\Model;

class WorkflowStoreModel extends Model
{
    protected $table = 'workflow_store';

    protected $guarded = [];

    public $timestamps = false;
}
