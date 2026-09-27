<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dispositivo registrado para push (FCM). Es del usuario, no de una organización.
 */
#[Fillable(['user_id', 'token', 'platform', 'last_used_at'])]
class DeviceToken extends Model
{
    protected function casts(): array
    {
        return ['last_used_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
