<?php

use App\Actions\Students\RegisterStudent;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Guardians\Pages\ManageGuardians;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

// Nunca se borra de verdad: tutores (y alumnos, inscripciones, asistencias) se archivan.
beforeEach(function () {
    Mail::fake();
    $this->jakare = Organization::factory()->create(['name' => 'Club Jakare', 'slug' => 'jakare']);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
});

it('eliminar un tutor desde el panel lo archiva y deja de verse en la ficha de sus hijos', function () {
    $this->actingAs($this->admin);
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);

    $student = Student::factory()->for($this->jakare)->create();
    $guardian = Guardian::factory()->for($this->jakare)->create(['document' => '1234567']);
    $student->guardians()->attach($guardian->id, ['relationship' => 'madre']);

    Livewire::test(ManageGuardians::class)->callTableAction('delete', $guardian);

    expect(Guardian::query()->find($guardian->id))->toBeNull()
        ->and(Guardian::withTrashed()->find($guardian->id)->trashed())->toBeTrue()
        ->and($student->fresh()->guardians)->toBeEmpty();
});

it('el alta restaura al tutor archivado con el mismo documento en vez de chocar con el índice único', function () {
    $guardian = Guardian::factory()->for($this->jakare)->create(['document' => '1234567', 'email' => null, 'phone' => null]);
    $guardian->delete();

    $season = Season::factory()->for($this->jakare)->create(['starts_on' => now()->startOfYear(), 'ends_on' => now()->endOfYear()]);
    $group = Group::factory()->for($this->jakare)->create(['program_id' => Program::factory()->for($this->jakare)->create()->id]);

    $student = app(RegisterStudent::class)->handle(
        $this->jakare,
        ['first_name' => 'Sofía', 'last_name' => 'Aquino', 'birth_date' => '2018-07-02', 'document' => '7654321'],
        $group,
        $season,
        null,
        guardians: [['first_name' => 'Rosa', 'last_name' => 'Aquino', 'document' => '1234567', 'relationship' => 'madre']],
    );

    expect(Guardian::withTrashed()->where('document', '1234567')->count())->toBe(1)
        ->and($guardian->fresh()->trashed())->toBeFalse()
        ->and($student->fresh()->guardians->pluck('id')->all())->toBe([$guardian->id]);
});
