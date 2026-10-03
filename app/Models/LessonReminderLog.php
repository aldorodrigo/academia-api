<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso de clase particular ya enviado (idempotencia de classes:remind).
 */
#[Fillable(['organization_id', 'reminder_key', 'sent_at'])]
class LessonReminderLog extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
