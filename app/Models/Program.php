<?php

namespace App\Models;

use App\Enums\GroupCriterion;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Disciplina o actividad (fútbol, pádel…). El nombre visible sale de term('program').
 */
#[Fillable(['organization_id', 'name', 'group_criterion'])]
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = ['group_criterion' => 'birth_year'];

    protected function casts(): array
    {
        return ['group_criterion' => GroupCriterion::class];
    }

    /**
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
