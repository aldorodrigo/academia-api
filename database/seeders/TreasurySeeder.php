<?php

namespace Database\Seeders;

use App\Actions\Treasury\ExpenseLedger;
use App\Actions\Treasury\TransferFunds;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\RecurringExpense;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Datos de desarrollo de tesorería: alquiler de cancha recurrente, un gasto de
 * árbitros, una transferencia y la tesorera (tesorero@academia.test) con "Ver informes".
 * Idempotente.
 */
class TreasurySeeder extends Seeder
{
    public function run(): void
    {
        $organization = app(CurrentOrganization::class)->get();
        $today = $organization->today();
        $bank = MoneyAccount::query()->firstOrCreate(['name' => 'Banco Itaú'], ['type' => 'banco']);
        $cash = MoneyAccount::query()->where('name', 'Caja')->firstOrFail();
        $ledger = app(ExpenseLedger::class);

        $supplier = Supplier::query()->firstOrCreate(['name' => 'Complejo Deportivo Luque'], ['phone' => '0981 555 010']);
        RecurringExpense::query()->firstOrCreate(['description' => 'Alquiler de cancha'], [
            'expense_category_id' => ExpenseCategory::query()->where('name', 'Alquiler de cancha')->value('id'),
            'supplier_id' => $supplier->id,
            'money_account_id' => $bank->id,
            'amount' => 1200000,
            'day_of_month' => 5,
            'starts_on' => $today->startOfMonth()->toDateString(),
        ]);
        $ledger->generateRecurring($organization, $today->startOfMonth());

        if (Expense::query()->where('description', 'Árbitros fecha 5')->doesntExist()) {
            $ledger->register([
                'expense_category_id' => ExpenseCategory::query()->where('name', 'Árbitros')->value('id'),
                'description' => 'Árbitros fecha 5',
                'amount' => 250000,
            ], $cash, $today->startOfMonth()->addDays(9));
        }

        if (Transfer::query()->doesntExist()) {
            app(TransferFunds::class)->handle($cash, $bank, 200000, $today->startOfMonth()->addDays(10), 'Depósito de la caja');
        }

        // La tesorera ve los informes en la app.
        $treasurer = User::query()->firstOrCreate(['email' => 'tesorero@academia.test'], ['name' => 'Laura Gómez', 'password' => 'password']);
        $organization->memberships()->updateOrCreate(['user_id' => $treasurer->id], ['status' => MembershipStatus::Active]);
        if (! $treasurer->hasCurrentRole($organization, OrganizationRole::Treasurer)) {
            app(RoleAssigner::class)->assign($organization, $treasurer, OrganizationRole::Treasurer, endsOn: $today->addYears(2));
        }
        Permission::findOrCreate('View:Reports');
        Role::query()->where('organization_id', $organization->id)->where('name', OrganizationRole::Treasurer->value)->firstOrFail()
            ->givePermissionTo('View:Reports');
    }
}
