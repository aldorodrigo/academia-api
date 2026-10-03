<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paquete que ofrece un profesor (ej. 4 clases por ₲ 100.000, válido 60 días desde que se paga).
 */
#[Fillable(['organization_id', 'user_id', 'classes', 'price', 'valid_days', 'is_active'])]
class LessonPack extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'classes' => 'integer',
            'price' => 'integer',
            'valid_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * "Paquete 4 clases".
     */
    public function label(): string
    {
        return 'Paquete '.($this->classes === 1 ? '1 clase' : "{$this->classes} clases");
    }
}
