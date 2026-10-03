<?php

namespace App\Actions\Lessons;

use App\Enums\ClassPackStatus;
use App\Models\ClassPack;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Extiende el vencimiento de un paquete activo o vencido con clases sin usar (queda en la
 * auditoría). Si estaba vencido y la nueva fecha no pasó, vuelve a estar activo.
 */
class ExtendClassPack
{
    public function handle(ClassPack $pack, CarbonImmutable $expiresOn): ClassPack
    {
        $today = $pack->organization->today();

        if (! in_array($pack->status, [ClassPackStatus::Active, ClassPackStatus::Expired], true) || $pack->remaining() === 0) {
            throw ValidationException::withMessages(['expires_on' => 'Solo se extiende un paquete activo o vencido con clases sin usar.']);
        }

        if ($expiresOn->lt($today)) {
            throw ValidationException::withMessages(['expires_on' => 'La nueva fecha no puede ser anterior a hoy.']);
        }

        if ($pack->expires_on !== null && ! $expiresOn->gt($pack->expires_on)) {
            throw ValidationException::withMessages(['expires_on' => 'La nueva fecha tiene que ser posterior al vencimiento actual.']);
        }

        $pack->update([
            'expires_on' => $expiresOn->toDateString(),
            'status' => ClassPackStatus::Active,
            'expiry_notified' => null,
        ]);

        return $pack;
    }
}
