<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence\Stub;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

use function substr;

class ScopedWorkflowStoreModel extends Model
{
    protected $table = 'scoped_workflow_store';

    protected $primaryKey = 'record_id';

    protected $guarded = [];

    protected $attributes = ['tenant' => 'application'];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query): Builder => $query->where('tenant', 'application'));
    }

    public function setValueAttribute(string $value): void
    {
        $this->attributes['value'] = 'wrapped:' . $value;
    }

    public function getValueAttribute(string $value): string
    {
        return substr($value, 8);
    }
}
