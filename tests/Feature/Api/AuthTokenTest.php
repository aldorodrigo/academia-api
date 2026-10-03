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
    ])->assertUnprocessable()->assertJsonValidationErrors('login');
});

it('entra con el celular en cualquier formato', function () {
    $user = User::factory()->create(['email' => null, 'phone' => '+595981123456', 'password' => 'secreto123']);

    foreach (['0981 123 456', '981123456', '+595 981 123-456'] as $login) {
        $this->postJson('/api/v1/auth/token', [
            'login' => $login,
            'password' => 'secreto123',
            'device_name' => 'app',
        ])->assertCreated();
    }

    expect($user->tokens()->count())->toBe(3);
});

it('entra con el correo como login (sin importar mayúsculas)', function () {
    User::factory()->create(['email' => 'ana@test.com', 'password' => 'secreto123']);

    $this->postJson('/api/v1/auth/token', ['login' => 'Ana@Test.com', 'password' => 'secreto123', 'device_name' => 'app'])
        ->assertCreated();
});

it('bloquea la cuenta 15 minutos después de 10 contraseñas incorrectas', function () {
    $user = User::factory()->create(['phone' => '+595981123456', 'password' => 'secreto123']);
    $attempt = fn (string $password) => $this->postJson('/api/v1/auth/token', [
        'login' => '0981123456', 'password' => $password, 'device_name' => 'app',
    ]);

    foreach (range(1, 10) as $i) {
        $this->travel(11)->seconds(); // el throttle de la ruta es 6 por minuto
        $attempt('incorrecta')->assertUnprocessable();
    }
    $this->travel(61)->seconds();
    $attempt('secreto123')->assertTooManyRequests()
        ->assertJsonPath('message', 'Demasiados intentos. Probá de nuevo en unos minutos.');

    $this->travel(16)->minutes();
    $attempt('secreto123')->assertCreated();
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
    $this->postJson('/api/v1/auth/token', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.device_name.0', 'El campo dispositivo es obligatorio.')
        ->assertJsonPath('errors.login.0', 'Ingresá tu celular o tu correo.');
});
