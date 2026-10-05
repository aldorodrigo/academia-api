<?php

use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\StudentWithdrawn;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Bandeja "Avisos" de la app (hallazgo N1): cada aviso queda guardado aunque la cuenta no tenga la app
 * instalada ni un correo verificado, por organización, con leído / no leído.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 11:32', 'America/Asuncion'));
    Mail::fake();
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->otra = Organization::factory()->create(['slug' => 'otra']);
    // Usa la app web (sin dispositivos) y su correo no está verificado.
    $this->laura = memberOf($this->jakare, ['name' => 'Laura Benítez', 'phone' => '+595981111222']);
    $this->laura->forceFill(['email_verified_at' => null])->save();
    $this->otra->memberships()->create(['user_id' => $this->laura->id, 'status' => MembershipStatus::Active]);
});

function inboxApi(User $user, string $method, string $uri, string $organization = 'jakare')
{
    return test()->actingAs($user, 'sanctum')->json($method, "/api/v1/{$uri}", [], ['X-Organization' => $organization]);
}

function sendIn(Organization $organization, User $user, string $title): void
{
    app(CurrentOrganization::class)->run($organization, fn () => $user->notify(new StudentWithdrawn($title, 'Hola, te contamos que registramos la baja.')));
}

it('guarda cada aviso en la bandeja aunque no haya push ni correo', function () {
    expect((new StudentWithdrawn('Baja', 'x'))->via($this->laura))->toBe(['inbox', 'push']);

    sendIn($this->jakare, $this->laura, 'Baja de Matías');

    Mail::assertNothingSent();
    // La baja crea su fila en `notifications`.
    expect(DB::table('notifications')->where('notifiable_id', $this->laura->id)->where('type', StudentWithdrawn::class)->count())->toBe(1);
    inboxApi($this->laura, 'GET', 'me/notifications')
        ->assertOk()
        ->assertJsonPath('meta.unread', 1)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.type', 'student_withdrawn')
        ->assertJsonPath('data.0.title', 'Baja de Matías')
        ->assertJsonPath('data.0.body', 'Hola, te contamos que registramos la baja.')
        ->assertJsonPath('data.0.route', '/inicio')
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('data.0.created_at', '2026-10-05T11:32:00-03:00');
});

it('cada organización ve sus avisos, los más nuevos primero, sin los del panel', function () {
    sendIn($this->jakare, $this->laura, 'Primero');
    $this->travel(5)->minutes();
    sendIn($this->jakare, $this->laura, 'Segundo');
    sendIn($this->otra, $this->laura, 'De la otra');
    FilamentNotification::make()->title('Importación terminada')->sendToDatabase($this->laura);

    expect(inboxApi($this->laura, 'GET', 'me/notifications')->json('data.*.title'))->toBe(['Segundo', 'Primero'])
        ->and(inboxApi($this->laura, 'GET', 'me/notifications', 'otra')->json('data.*.title'))->toBe(['De la otra']);

    // Otro usuario no los ve.
    $otro = memberOf($this->jakare);
    inboxApi($otro, 'GET', 'me/notifications')->assertJsonPath('meta.total', 0);
});

it('marca leído uno o todos; uno ajeno es 404', function () {
    sendIn($this->jakare, $this->laura, 'Uno');
    sendIn($this->jakare, $this->laura, 'Dos');
    sendIn($this->otra, $this->laura, 'De la otra');
    $id = inboxApi($this->laura, 'GET', 'me/notifications')->json('data.0.id');

    inboxApi($this->laura, 'POST', "me/notifications/{$id}/read")
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.read_at', '2026-10-05T11:32:00-03:00');
    inboxApi($this->laura, 'GET', 'me/notifications')->assertJsonPath('meta.unread', 1);

    $otro = memberOf($this->jakare);
    inboxApi($otro, 'POST', "me/notifications/{$id}/read")->assertNotFound();

    inboxApi($this->laura, 'POST', 'me/notifications/read-all')->assertOk()->assertJsonPath('data.unread', 0);
    inboxApi($this->laura, 'GET', 'me/notifications')->assertJsonPath('meta.unread', 0);
    // Solo los de la organización activa.
    inboxApi($this->laura, 'GET', 'me/notifications', 'otra')->assertJsonPath('meta.unread', 1);
});

it('pagina de a 20', function () {
    foreach (range(1, 21) as $i) {
        sendIn($this->jakare, $this->laura, "Aviso {$i}");
    }

    inboxApi($this->laura, 'GET', 'me/notifications')
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('meta.unread', 21);
    inboxApi($this->laura, 'GET', 'me/notifications?page=2')->assertJsonCount(1, 'data');
});
