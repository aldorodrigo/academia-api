<?php

namespace App\Models;

use App\Enums\GroupCriterion;
use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grupo dentro de un programa (Sub-10, Inicial…). El nombre visible sale de term('group').
 */
#[Fillable(['organization_id', 'program_id', 'name', 'min_age', 'max_age', 'level', 'capacity', 'is_active'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use BelongsToOrganization, HasFactory;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'min_age' => 'integer',
            'max_age' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->orderBy('weekday')->orderBy('starts_at');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function instructors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_instructor')->withTimestamps();
    }

    /**
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Años de nacimiento que corresponden al grupo en la temporada (criterio por edad).
     * Sub-10 en 2026 con edades 9–10 → [2016, 2017].
     *
     * @return list<int>
     */
    public function birthYearsFor(Season $season): array
    {
        if ($this->max_age === null) {
            return [];
        }

        $year = $season->starts_on->year;
        $minAge = $this->min_age ?? $this->max_age;

        return range($year - $this->max_age, $year - $minAge);
    }

    /**
     * Categoría que corresponde por año de nacimiento en la temporada (ej. 2016 → Sub-10).
     * Solo si hay exactamente una; con varias disciplinas se puede acotar por disciplina (modelo o nombre).
     */
    public static function suggestFor(CarbonInterface $birthDate, Season $season, Program|string|null $program = null): ?self
    {
        $matches = static::query()
            ->where('is_active', true)
            ->whereNotNull('max_age')
            ->whereHas('program', fn (Builder $programs) => $programs
                ->where('group_criterion', GroupCriterion::BirthYear)
                ->when($program instanceof Program, fn (Builder $query) => $query->whereKey($program->getKey()))
                ->when(is_string($program), fn (Builder $query) => $query->where('name', $program)))
            ->get()
            ->filter(fn (Group $group) => in_array($birthDate->year, $group->birthYearsFor($season), true));

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
