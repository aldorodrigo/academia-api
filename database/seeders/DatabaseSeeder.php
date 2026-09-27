<?php

namespace Database\Seeders;

use App\Actions\Organizations\EnsureOrganizationRoles;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Datos de desarrollo: super admin + organización piloto.
     */
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@academia.test'],
            ['name' => 'Administrador', 'password' => 'password', 'is_super_admin' => true],
        );

        $jakare = Organization::query()->updateOrCreate(
            ['slug' => 'jakare'],
            ['name' => 'Club Jakare', 'type' => OrganizationType::Club],
        );

        // Por si la organización ya existía antes de los roles base.
        app(EnsureOrganizationRoles::class)->handle($jakare);

        $jakare->memberships()->updateOrCreate(
            ['user_id' => $admin->id],
            ['status' => MembershipStatus::Active],
        );

        $jakare->seasons()->updateOrCreate(
            ['name' => '2026'],
            ['starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_current' => true],
        );

        app(CurrentOrganization::class)->run($jakare, fn () => $this->call(AcademicSeeder::class));
    }
}
