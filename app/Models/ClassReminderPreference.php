<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Si el usuario (tutor o alumno adulto) quiere el aviso de los días de clase de un alumno.
 */
#[Fillable(['organization_id', 'user_id', 'student_id', 'enabled'])]
class ClassReminderPreference extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Preferencia guardada, o null si nunca respondió.
     */
    public static function for(User $user, Student $student): ?bool
    {
        return static::query()->where('user_id', $user->id)->where('student_id', $student->id)->value('enabled');
    }
}
