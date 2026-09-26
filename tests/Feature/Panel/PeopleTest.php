<?php

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    $this->jakare = Organization::factory()->create(['name' => 'Club Jakare', 'slug' => 'jakare']);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
});

function actingInPanel($user, Organization $organization): void
{
    test()->actingAs($user);
    filament()->setTenant($organization);
    app(CurrentOrganization::class)->set($organization);
}

it('el admin ve las páginas de personas y configuración', function () {
    $this->actingAs($this->admin);

    foreach (['/admin/jakare/miembros', '/admin/jakare/invitaciones', '/admin/jakare/profile'] as $url) {
        $this->get($url)->assertOk();
    }
});

it('un tutor no ve personas ni configuración', function () {
    $tutor = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $tutor, OrganizationRole::Guardian);

    $this->actingAs($tutor);

    $this->get('/admin/jakare/miembros')->assertForbidden();
    $this->get('/admin/jakare/invitaciones')->assertForbidden();
    $this->get('/admin/jakare/profile')->assertNotFound();
});

it('invitar desde el panel crea la invitación, envía el email y muestra el link', function () {
    actingInPanel($this->admin, $this->jakare);

    $page = Livewire::test(ListInvitations::class)
        ->callAction('invite', data: [
            'email' => 'Tesorero@Test.com',
            'roles' => [
                ['role' => 'tutor'],
                ['role' => 'tesorero', 'starts_on' => '2026-01-01', 'ends_on' => '2027-12-31'],
            ],
        ])
        // Si el formulario tuviera errores no se abriría el modal del link.
        ->assertActionMounted('showLink');

    $invitation = Invitation::query()->sole();
    $token = $page->get('mountedActions')[0]['arguments']['token'];

    // El modal muestra el único token que existe en claro.
    expect(Invitation::hashToken($token))->toBe($invitation->token_hash);

    expect($invitation->email)->toBe('tesorero@test.com')
        ->and($invitation->roleLabels())->toBe(['Tutor', 'Tesorero'])
        ->and($invitation->invited_by)->toBe($this->admin->id);

    Mail::assertQueued(InvitationMail::class);
});

it('un cargo de comisión exige fin de mandato en el formulario', function () {
    actingInPanel($this->admin, $this->jakare);

    Livewire::test(ListInvitations::class)
        ->callAction('invite', data: [
            'email' => 'ana@test.com',
            'roles' => [['role' => 'presidente']],
        ])
        ->assertHasFormErrors();

    expect(Invitation::query()->count())->toBe(0);
});

it('asignar y quitar un rol desde miembros', function () {
    $member = memberOf($this->jakare);
    $membership = $this->jakare->memberships()->where('user_id', $member->id)->sole();
    actingInPanel($this->admin, $this->jakare);

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('assignRole')->table($membership), data: [
            'role' => 'secretario',
            'ends_on' => '2027-12-31',
        ])
        ->assertHasNoFormErrors();

    $assignment = $member->currentRoleAssignments($this->jakare)->sole();
    expect($assignment->description())->toBe('Secretario · hasta 31/12/2027');

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('endRole')->table($membership), data: [
            'assignment' => $assignment->id,
        ]);

    expect($member->currentRoleAssignments($this->jakare))->toBeEmpty();
});

it('desactivar un miembro le quita el acceso', function () {
    $member = memberOf($this->jakare);
    $membership = $this->jakare->memberships()->where('user_id', $member->id)->sole();
    actingInPanel($this->admin, $this->jakare);

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('toggleStatus')->table($membership));

    expect($membership->fresh()->status)->toBe(MembershipStatus::Inactive)
        ->and($member->belongsToOrganization($this->jakare))->toBeFalse();
});

it('editar el vocabulario; una etiqueta vacía vuelve al valor por defecto', function () {
    actingInPanel($this->admin, $this->jakare);

    Livewire::test(EditOrganizationProfile::class)
        ->fillForm([
            'name' => 'Club Jakare',
            'type' => 'club',
            'terminology' => ['group' => 'Nivel', 'student' => ''],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $jakare = $this->jakare->fresh();

    expect($jakare->term('group'))->toBe('Nivel')
        ->and($jakare->term('student'))->toBe('Jugador');
});
