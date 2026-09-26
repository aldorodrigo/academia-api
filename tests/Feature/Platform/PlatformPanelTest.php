<?php

use App\Actions\Invitations\CreateInvitation;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\EditOrganizationProfile;
use App\Filament\Platform\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Platform\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Platform\Resources\Users\Pages\ListUsers;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Mail::fake();
    $this->root = User::factory()->create(['is_super_admin' => true]);
    $this->jakare = Organization::factory()->create(['name' => 'Club Jakare', 'slug' => 'jakare']);
});

function inPlatform(User $user): void
{
    test()->actingAs($user);
    filament()->setCurrentPanel(filament()->getPanel('platform'));
}

describe('acceso', function () {
    it('el super admin entra a la plataforma', function () {
        $this->actingAs($this->root);

        foreach (['/plataforma', '/plataforma/organizaciones', '/plataforma/usuarios'] as $url) {
            $this->get($url)->assertOk();
        }
    });

    it('un admin de organización no entra a la plataforma', function () {
        $admin = memberOf($this->jakare);
        app(RoleAssigner::class)->assign($this->jakare, $admin, OrganizationRole::Admin);

        $this->actingAs($admin)->get('/plataforma')->assertForbidden();
    });

    it('sin sesión redirige al login de la plataforma', function () {
        $this->get('/plataforma/organizaciones')->assertRedirect('/plataforma/login');
    });
});

describe('organizaciones', function () {
    it('crear una organización genera sus roles e invita a su primer admin', function () {
        inPlatform($this->root);

        $page = Livewire::test(ListOrganizations::class)
            ->callAction('createOrganization', data: [
                'name' => 'Academia Prueba',
                'slug' => 'academia-prueba',
                'type' => 'academy',
                'timezone' => 'America/Asuncion',
                'country' => 'PY',
                'currency' => 'PYG',
                'features' => ['board'],
                'admin_email' => 'admin@prueba.test',
            ])
            ->assertActionMounted('showLink');

        $organization = Organization::query()->where('slug', 'academia-prueba')->sole();
        $invitation = Invitation::withoutGlobalScopes()->where('organization_id', $organization->id)->sole();

        expect($organization->features)->toBe(['board'])
            ->and(Role::query()->where('organization_id', $organization->id)->count())->toBe(count(OrganizationRole::cases()))
            ->and($invitation->email)->toBe('admin@prueba.test')
            ->and($invitation->roles)->toBe([['role' => 'admin', 'starts_on' => null, 'ends_on' => null]])
            ->and(Invitation::hashToken($page->get('mountedActions')[0]['arguments']['token']))->toBe($invitation->token_hash);

        Mail::assertQueued(InvitationMail::class);
    });

    it('el slug es único', function () {
        inPlatform($this->root);

        Livewire::test(ListOrganizations::class)
            ->callAction('createOrganization', data: [
                'name' => 'Otro Jakare',
                'slug' => 'jakare',
                'type' => 'club',
                'timezone' => 'America/Asuncion',
                'country' => 'PY',
                'currency' => 'PYG',
                'admin_email' => 'otro@test.com',
            ])
            ->assertHasActionErrors(['slug']);
    });

    it('editar no cambia el slug', function () {
        inPlatform($this->root);

        Livewire::test(EditOrganization::class, ['record' => $this->jakare->getRouteKey()])
            ->fillForm(['name' => 'Club Jakare FC', 'slug' => 'otro'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->jakare->fresh())
            ->name->toBe('Club Jakare FC')
            ->slug->toBe('jakare');
    });
});

describe('suspensión', function () {
    beforeEach(function () {
        $this->member = memberOf($this->jakare);
        [, $this->token] = app(CreateInvitation::class)
            ->handle($this->jakare, 'nueva@test.com', [['role' => 'tutor']]);

        inPlatform($this->root);
        Livewire::test(ListOrganizations::class)
            ->callAction(TestAction::make('suspend')->table($this->jakare), data: ['reason' => 'Falta de pago']);
    });

    it('bloquea a sus miembros en la API, el panel y las invitaciones', function () {
        expect($this->jakare->fresh()->isSuspended())->toBeTrue();

        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/v1/me')
            ->assertJsonCount(0, 'data.organizations');

        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
            ->assertForbidden()
            ->assertJsonPath('message', 'La organización está suspendida.');

        $this->actingAs($this->member, 'web')->get('/admin/jakare')->assertForbidden();

        $this->getJson("/api/v1/invitations/{$this->token}")->assertNotFound();
    });

    it('el super admin sigue entrando', function () {
        $this->actingAs($this->root)->get('/admin/jakare')->assertOk();
    });

    it('reactivar devuelve el acceso y todo queda auditado', function () {
        Livewire::test(ListOrganizations::class)
            ->callAction(TestAction::make('reactivate')->table($this->jakare));

        $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/v1/organization', ['X-Organization' => 'jakare'])
            ->assertOk();

        expect(Activity::query()->where('log_name', 'platform')->pluck('description')->all())
            ->toContain('Organización suspendida', 'Organización reactivada');
    });
});

describe('super admins', function () {
    it('otorgar y quitar super admin', function () {
        $user = User::factory()->create();
        inPlatform($this->root);

        Livewire::test(ListUsers::class)->callAction(TestAction::make('grantSuperAdmin')->table($user));
        expect($user->fresh()->is_super_admin)->toBeTrue();

        Livewire::test(ListUsers::class)->callAction(TestAction::make('revokeSuperAdmin')->table($user));
        expect($user->fresh()->is_super_admin)->toBeFalse();
    });

    it('no se puede quitar a uno mismo', function () {
        User::factory()->create(['is_super_admin' => true]);
        inPlatform($this->root);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('revokeSuperAdmin')->table($this->root))
            ->assertNotified('No podés quitarte el acceso de super admin a vos mismo.');

        expect($this->root->fresh()->is_super_admin)->toBeTrue();
    });

    it('no se puede quitar al último super admin', function () {
        $this->artisan('users:super-admin', ['email' => $this->root->email, '--revoke' => true])
            ->expectsOutput('Tiene que quedar al menos un super admin.')
            ->assertFailed();

        expect($this->root->fresh()->is_super_admin)->toBeTrue();
    });
});

it('el admin de la organización no puede cambiar los módulos; el super admin sí', function () {
    $admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $admin, OrganizationRole::Admin);
    $this->jakare->update(['features' => ['board']]);

    $asUser = function (User $user) {
        test()->actingAs($user);
        filament()->setCurrentPanel(filament()->getPanel('admin'));
        filament()->setTenant($this->jakare);
        app(CurrentOrganization::class)->set($this->jakare);
    };

    $asUser($admin);
    Livewire::test(EditOrganizationProfile::class)
        ->assertFormFieldDisabled('features')
        ->fillForm(['features' => ['board', 'apparel']])
        ->call('save');
    expect($this->jakare->fresh()->features)->toBe(['board']);

    $asUser($this->root);
    Livewire::test(EditOrganizationProfile::class)
        ->fillForm(['features' => ['board', 'apparel']])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($this->jakare->fresh()->features)->toBe(['board', 'apparel']);
});
