<?php

namespace Database\Seeders;

use App\Actions\Students\RegisterStudent;
use App\Enums\EnrollmentStatus;
use App\Enums\GroupCriterion;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Guardian;
use App\Models\Program;
use App\Models\Season;
use App\Models\Student;
use App\Models\User;
use App\Models\Venue;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;

/**
 * Datos de desarrollo de la organización activa: Fútbol Sub-8…Sub-14 con horarios,
 * algunos jugadores y tutor@academia.test / password con dos hijos.
 */
class AcademicSeeder extends Seeder
{
    public function run(): void
    {
        $organization = app(CurrentOrganization::class)->get();
        $season = Season::query()->active()->orderByDesc('starts_on')->first();

        $canchas = collect(['Cancha 1', 'Cancha 2'])
            ->map(fn (string $name) => Venue::query()->firstOrCreate(['name' => $name]));

        $futbol = Program::query()->firstOrCreate(['name' => 'Fútbol'], ['group_criterion' => GroupCriterion::BirthYear]);
        $season?->programs()->syncWithoutDetaching([$futbol->id]);

        $groups = collect([8, 10, 12, 14])->mapWithKeys(function (int $age, int $i) use ($futbol, $canchas) {
            $group = $futbol->groups()->firstOrCreate(
                ['name' => "Sub-{$age}"],
                ['organization_id' => $futbol->organization_id, 'min_age' => $age - 1, 'max_age' => $age],
            );

            if ($group->schedules()->doesntExist()) {
                foreach ($i % 2 === 0 ? [1, 3] : [2, 4] as $weekday) {
                    $group->schedules()->create([
                        'weekday' => $weekday,
                        'starts_at' => '17:00',
                        'ends_at' => '18:30',
                        'venue_id' => $canchas[$i % 2]->id,
                    ]);
                }
            }

            return [$age => $group];
        });

        $instructor = User::query()->firstOrCreate(
            ['email' => 'tecnico@academia.test'],
            ['name' => 'Carlos Gómez', 'password' => 'password'],
        );
        $this->member($instructor, OrganizationRole::Instructor);
        $groups->each(fn ($group) => $group->instructors()->syncWithoutDetaching([$instructor->id]));

        $tutor = User::query()->firstOrCreate(
            ['email' => 'tutor@academia.test'],
            ['name' => 'Ana Benítez', 'password' => 'password'],
        );
        $this->member($tutor, OrganizationRole::Guardian);

        $year = $season?->starts_on->year ?? now()->year;
        $children = [
            ['Mateo', 'Benítez', '6123456', ($year - 10).'-03-14', 10, EnrollmentStatus::Active],
            ['Sofía', 'Benítez', '7234567', ($year - 8).'-07-02', 8, EnrollmentStatus::Scholarship],
        ];

        // Alta como en el panel: datos + inscripción + tutor (familia automática).
        foreach ($children as [$first, $last, $document, $birth, $age, $status]) {
            if ($season !== null) {
                app(RegisterStudent::class)->handle(
                    $organization,
                    ['first_name' => $first, 'last_name' => $last, 'document' => $document, 'birth_date' => $birth, 'shirt_size' => (string) $age],
                    $groups[$age],
                    $season,
                    $status,
                    [['first_name' => 'Ana', 'last_name' => 'Benítez', 'email' => 'tutor@academia.test', 'phone' => '0981 123 456', 'relationship' => 'madre']],
                );
            }
        }

        Guardian::query()->where('email', 'tutor@academia.test')->update(['user_id' => $tutor->id]);

        Student::query()->where('document', '6123456')->first()->medicalRecord()->firstOrCreate([], [
            'organization_id' => $organization->id,
            'blood_type' => 'O+',
            'allergies' => 'Penicilina',
            'emergency_contact_name' => 'Ana Benítez',
            'emergency_contact_phone' => '0981 123 456',
            'fit_until' => ($year + 1).'-03-01',
        ]);

        // Otros jugadores para ver las listas con datos.
        foreach ([['Lucas', 'Ramírez', 12], ['Diego', 'Ortiz', 14], ['Tomás', 'Villalba', 12], ['Joaquín', 'Duarte', 8]] as $i => [$first, $last, $age]) {
            $student = Student::query()->firstOrCreate(
                ['document' => (string) (5_000_000 + $i)],
                ['first_name' => $first, 'last_name' => $last, 'birth_date' => ($year - $age).'-05-10'],
            );
            $this->enroll($student, $groups[$age], $season, EnrollmentStatus::Active);
        }

        $this->call(BillingSeeder::class);
        $this->call(TreasurySeeder::class);
    }

    private function member(User $user, OrganizationRole $role): void
    {
        $organization = app(CurrentOrganization::class)->get();
        $organization->memberships()->updateOrCreate(['user_id' => $user->id], ['status' => MembershipStatus::Active]);

        if (! $user->hasCurrentRole($organization, $role)) {
            app(RoleAssigner::class)->assign($organization, $user, $role);
        }
    }

    private function enroll(Student $student, Group $group, ?Season $season, EnrollmentStatus $status): void
    {
        if ($season === null) {
            return;
        }

        Enrollment::query()->firstOrCreate(
            ['student_id' => $student->id, 'group_id' => $group->id, 'season_id' => $season->id],
            ['status' => $status, 'enrolled_on' => $season->starts_on],
        );
    }
}
