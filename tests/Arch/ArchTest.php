<?php

use App\Models\Concerns\BelongsToOrganization;
use App\Models\DeviceToken;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;

arch('los modelos de dominio pertenecen a una organización')
    ->expect('App\Models')
    ->classes()
    ->extending('Illuminate\Database\Eloquent\Model')
    ->toUseTrait(BelongsToOrganization::class)
    ->ignoring([
        // Modelos de plataforma: se consultan entre organizaciones.
        Organization::class,
        Membership::class,
        User::class,
        // Dispositivos para push: son del usuario, no de una organización.
        DeviceToken::class,
        // spatie/permission filtra por equipo (organization_id).
        Role::class,
        'App\Models\Concerns',
        'App\Models\Scopes',
    ]);

arch('no quedan funciones de depuración')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
