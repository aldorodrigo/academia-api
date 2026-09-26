<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $current = app(CurrentOrganization::class);

        if ($current->check()) {
            $builder->where($model->qualifyColumn('organization_id'), $current->id());
        }
    }
}
