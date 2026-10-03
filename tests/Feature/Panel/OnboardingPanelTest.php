<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\RegisterAccount;
use App\Filament\Pages\Auth\VerifyAccount;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Onboarding;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Filament\Resources\Seasons\Pages\CreateSeason;
use App\Jobs\SendWhatsAppCode;
use App\Mail\EmailVerificationCodeMail;
use App\Mail\InvitationMail;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Venue;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-03 10:00:00');
    Mail::fake();
    filament()->setCurrentPanel(filament()->getPanel('admin'));
});

/** Admin de un club recién creado, con el panel en ese club. */
function guideAdmin(): array
{
    $club = Organization::factory()->create(['slug' => 'ritmo', 'name' => 'Academia Ritmo']);
    $admin = memberOf($club, ['email' => 'laura@test.com']);
    app(RoleAssigner::class)->assign($club, $admin, OrganizationRole::Admin);

    test()->actingAs($admin);
    filament()->setTenant($club);
    app(CurrentOrganization::class)->set($club);

    return [$club, $admin];
}

describe('alta desde el panel', function () {
    it('crear la cuenta pide aceptar los términos', function () {
        Livewire::test(RegisterAccount::class)
            ->fillForm([
                'name' => 'Laura Gómez',
                'email' => 'laura@test.com',
                'password' => 'secreta123',
                'passwordConfirmation' => 'secreta123',
            ])
            ->call('register')
            ->assertHasFormErrors(['terms' => 'accepted']);

        expect(User::query()->where('email', 'laura@test.com')->exists())->toBeFalse();
    });

    it('crear la cuenta con el celular manda el código por WhatsApp', function () {
        Bus::fake([SendWhatsAppCode::class]);

        Livewire::test(RegisterAccount::class)
            ->fillForm([
                'name' => 'Laura Gómez',
                'phone' => '0981 123 456',
                'password' => 'secreta123',
                'passwordConfirmation' => 'secreta123',
                'terms' => true,
            ])
            ->tap(fn () => $this->travel(3)->seconds())
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::query()->where('phone', '+595981123456')->firstOrFail();
        expect($user->isVerified())->toBeFalse()->and($user->email)->toBeNull();
        Bus::assertDispatched(SendWhatsAppCode::class, fn (SendWhatsAppCode $job) => $job->phone === '+595981123456');
        Mail::assertNothingQueued();
    });

    it('un bot que completa el formulario al instante no crea la cuenta', function () {
        Livewire::test(RegisterAccount::class)
            ->fillForm([
                'name' => 'Bot',
                'phone' => '0981 123 456',
                'password' => 'secreta123',
                'passwordConfirmation' => 'secreta123',
                'terms' => true,
            ])
            ->call('register')
            ->assertHasErrors(['data.name']);

        $this->travel(3)->seconds();
        Livewire::test(RegisterAccount::class)
            ->fillForm([
                'name' => 'Bot',
                'phone' => '0981 123 456',
                'password' => 'secreta123',
                'passwordConfirmation' => 'secreta123',
                'terms' => true,
                'website' => 'http://spam.test',
            ])
            ->tap(fn () => $this->travel(3)->seconds())
            ->call('register')
            ->assertHasErrors(['data.name']);

        expect(User::query()->where('phone', '+595981123456')->exists())->toBeFalse();
    });

    it('crear la cuenta la deja sin verificar y manda el código', function () {
        Livewire::test(RegisterAccount::class)
            ->fillForm([
                'name' => 'Laura Gómez',
                'via' => 'mail',
                'email' => 'laura@test.com',
                'password' => 'secreta123',
                'passwordConfirmation' => 'secreta123',
                'terms' => true,
            ])
            ->tap(fn () => $this->travel(3)->seconds())
            ->call('register')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'laura@test.com')->firstOrFail();
        expect($user->hasVerifiedEmail())->toBeFalse()->and($user->terms_version)->toBe('2026-10');
        Mail::assertQueued(EmailVerificationCodeMail::class);

        // Sin verificar, el panel pide el código.
        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/new');
        $this->actingAs($user)->get('/admin/new')->assertRedirect('/admin/email-verification/prompt');
    });

    it('el código verifica la cuenta y sigue a "Tu club"', function () {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        $user->sendEmailVerificationNotification();
        $code = null;
        Mail::assertQueued(EmailVerificationCodeMail::class, function (EmailVerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        Livewire::test(VerifyAccount::class)
            ->fillForm(['code' => $code === '111111' ? '222222' : '111111'])
            ->call('verify')
            ->assertHasErrors(['data.code']);

        Livewire::test(VerifyAccount::class)
            ->fillForm(['code' => $code])
            ->call('verify')
            ->assertRedirect(filament()->getUrl());

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
        $this->get('/admin')->assertRedirect('/admin/new');
    });

    it('"Tu club" crea la organización y entra a la guía', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => 'Academia Ritmo'])
            ->assertSchemaStateSet(['slug' => 'academia-ritmo'])
            ->fillForm(['type' => 'academy'])
            ->assertSchemaStateSet(['terminology.student' => 'Alumno', 'terminology.instructor' => 'Profesor'])
            ->call('register')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/academia-ritmo/primeros-pasos');

        $club = Organization::query()->where('slug', 'academia-ritmo')->firstOrFail();
        expect($user->isOrganizationAdmin($club))->toBeTrue()
            ->and($club->self_service)->toBeTrue()
            ->and($club->term('group'))->toBe('Grupo');
    });
});

describe('guía en el panel', function () {
    it('el Escritorio lleva a la guía mientras esté incompleta y no se haya cerrado', function () {
        [$club] = guideAdmin();

        Livewire::test(Dashboard::class)->assertRedirect(Onboarding::getUrl());

        Livewire::test(Onboarding::class)->callAction('dismiss');
        expect($club->fresh()->onboarding_dismissed_at)->not->toBeNull();

        Livewire::test(Dashboard::class)->assertNoRedirect();
    });

    it('solo la ve el administrador', function () {
        [$club] = guideAdmin();
        $tutor = memberOf($club);
        app(RoleAssigner::class)->assign($club, $tutor, OrganizationRole::Guardian);

        $this->actingAs($tutor)->get('/admin/ritmo/primeros-pasos')->assertForbidden();
    });

    it('disciplinas, categorías y técnicos desde los paneles laterales', function () {
        [$club, $admin] = guideAdmin();

        Livewire::test(Onboarding::class)
            ->assertSee('0 de 4')
            ->callAction('programs', data: [
                'programs' => ['Fútbol'],
                'custom' => [['name' => 'Ajedrez', 'group_criterion' => 'level']],
            ])
            ->assertHasNoActionErrors();

        $futbol = Program::query()->where('name', 'Fútbol')->firstOrFail();
        expect(Program::query()->where('name', 'Ajedrez')->value('group_criterion')->value)->toBe('level');

        Livewire::test(Onboarding::class)
            ->mountAction('groups')
            ->setActionData(['program_id' => $futbol->id])
            ->assertActionDataSet(['groups.0.name' => 'Sub-6'])
            ->setActionData([
                'groups' => [
                    ['name' => 'Sub-8', 'min_age' => 7, 'max_age' => 8, 'level' => null],
                    ['name' => 'Sub-10', 'min_age' => 9, 'max_age' => 10, 'level' => null],
                ],
                'weekdays' => [2, 4],
                'starts_at' => '17:00',
                'ends_at' => '18:30',
                'venue_name' => 'Cancha del club',
                'capacity' => 20,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $sub8 = Group::query()->where('name', 'Sub-8')->firstOrFail();
        expect($sub8->capacity)->toBe(20)
            ->and($sub8->schedules->pluck('weekday')->all())->toBe([2, 4])
            ->and($sub8->schedules->first()->venue->name)->toBe('Cancha del club')
            ->and(Venue::query()->count())->toBe(1);

        Livewire::test(Onboarding::class)
            ->callAction('teaching', data: ['teaches' => true, 'group_ids' => [$sub8->id]])
            ->callAction('inviteInstructor', data: ['name' => 'Marta Ríos', 'contact' => 'marta@test.com', 'group_ids' => [$sub8->id]])
            ->assertActionMounted('showLink');

        expect($admin->hasCurrentRole($club, OrganizationRole::Instructor))->toBeTrue()
            ->and($admin->instructedGroups()->pluck('groups.id')->all())->toBe([$sub8->id]);
        Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('marta@test.com'));

        expect(collect(Onboarding::checklist()['steps'])->pluck('status', 'key')->all())->toBe([
            'programs' => 'done',
            'groups' => 'done',
            'season' => 'pending',
            'instructors' => 'done',
        ]);
    });

    it('el asistente de temporada vuelve a la guía y la completa', function () {
        [$club] = guideAdmin();
        $futbol = Program::factory()->for($club)->create(['name' => 'Fútbol']);
        $group = Group::factory()->for($futbol)->create(['organization_id' => $club->id]);
        Schedule::query()->create(['group_id' => $group->id, 'weekday' => 2, 'starts_at' => '17:00', 'ends_at' => '18:30']);
        Livewire::test(Onboarding::class)->callAction('skipInstructors');

        Livewire::withQueryParams(['guia' => 1])
            ->test(CreateSeason::class)
            ->fillForm(['fee_amount' => 150000])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(Onboarding::getUrl());

        expect(Onboarding::checklist()['completed'])->toBeTrue()
            ->and($club->fresh()->onboarding_completed_at)->not->toBeNull();

        Livewire::test(Onboarding::class)->assertSee('¡Todo listo!');
        Livewire::test(Dashboard::class)->assertNoRedirect();
    });
});
