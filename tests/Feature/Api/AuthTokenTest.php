<?php

use App\Models\Organization;
use App\Models\User;

it('emite un token con credenciales válidas', function () {
    $user = User::factory()->create(['password' => 'secreto123']);

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'secreto123',
        'device_name' => 'pixel-7',
    ])->assertCreated()->assertJsonStructure(['token']);
});

it('rechaza credenciales inválidas', function () {
    $user = User::factory()->create(['password' => 'secreto123']);

    $this->postJson('/api/v1/auth/token', [
        'email' => $user->email,
        'password' => 'incorrecta',
        'device_name' => 'pixel-7',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('devuelve el usuario con sus organizaciones activas', function () {
    $jakare = Organization::factory()->create(['name' => 'Jakare', 'slug' => 'jakare']);
    Organization::factory()->create(['slug' => 'ajena']);
    $user = memberOf($jakare);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonCount(1, 'data.organizations')
        ->assertJsonPath('data.organizations.0.slug', 'jakare');
});

it('responde los errores de validación en español', function () {
    $this->postJson('/api/v1/auth/token', ['email' => 'no-es-un-email'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.device_name.0', 'El campo dispositivo es obligatorio.')
        ->assertJsonPath('errors.email.0', 'El campo correo electrónico no es un correo válido.');
});
