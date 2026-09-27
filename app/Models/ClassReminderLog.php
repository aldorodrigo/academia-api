<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso de día de clase ya enviado (idempotencia de classes:remind).
 */
#[Fillable(['organization_id', 'class_session_id', 'user_id', 'student_id', 'offset', 'sent_at'])]
class ClassReminderLog extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'student_id' => 'integer'];
    }
}
