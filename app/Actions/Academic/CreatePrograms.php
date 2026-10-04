<?php

namespace App\Actions\Academic;

use App\Enums\GroupCriterion;
use App\Models\Program;
use Illuminate\Support\Collection;

/**
 * Crea las disciplinas elegidas en la guía; las que ya existen (sin importar mayúsculas)
 * no se duplican.
 */
class CreatePrograms
{
    /**
     * @param  list<array{name: string, group_criterion?: ?string}>  $programs
     * @return Collection<int, Program> todas las disciplinas de la organización
     */
    public function handle(array $programs): Collection
    {
        $existing = Program::query()->pluck('name')->map(fn (string $name) => mb_strtolower($name));

        foreach ($programs as $program) {
            $name = trim($program['name']);

            if ($name === '' || $existing->contains(mb_strtolower($name))) {
                continue;
            }

            Program::query()->create([
                'name' => $name,
                'group_criterion' => GroupCriterion::tryFrom($program['group_criterion'] ?? '') ?? GroupCriterion::BirthYear,
            ]);
            $existing->push(mb_strtolower($name));
        }

        return Program::query()->withCount('groups')->orderBy('name')->get();
    }
}
