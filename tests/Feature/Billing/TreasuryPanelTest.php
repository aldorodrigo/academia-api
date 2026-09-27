<?php

use App\Enums\ExpenseStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Reports;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Filament\Resources\RecurringExpenses\Pages\ManageRecurringExpenses;
use App\Filament\Resources\Transfers\Pages\ManageTransfers;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\Organization;
use App\Models\RecurringExpense;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 12:00:00');
    Storage::fake('local');
    $this->jakare = Organization::factory()->create(['slug' => 'jakare']);
    $this->admin = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $this->admin, OrganizationRole::Admin);
    $this->actingAs($this->admin);
    filament()->setTenant($this->jakare);
    app(CurrentOrganization::class)->set($this->jakare);
    $this->cash = MoneyAccount::query()->where('name', 'Caja')->sole();
    $this->bank = MoneyAccount::factory()->for($this->jakare)->create(['name' => 'Banco Itaú']);
});

it('las páginas de tesorería e informes cargan', function (string $path) {
    $this->get("/admin/jakare/{$path}")->assertOk();
})->with(['gastos', 'gastos-recurrentes', 'proveedores', 'transferencias', 'informes', 'informes?tab=saldos', 'informes?tab=morosos']);

it('un tutor no ve los informes', function () {
    $tutor = memberOf($this->jakare);
    app(RoleAssigner::class)->assign($this->jakare, $tutor, OrganizationRole::Guardian);

    $this->actingAs($tutor)->get('/admin/jakare/informes')->assertForbidden();
});

it('registrar gasto con comprobante', function () {
    Livewire::test(ManageExpenses::class)
        ->callAction('register', data: [
            'description' => 'Árbitros fecha 5',
            'expense_category_id' => ExpenseCategory::query()->where('name', 'Árbitros')->value('id'),
            'amount' => 250000,
            'money_account_id' => $this->cash->id,
            'paid_on' => '2026-09-14',
            'attachment' => [UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf')],
        ])
        ->assertHasNoActionErrors();

    $expense = Expense::query()->sole();
    expect($expense->status)->toBe(ExpenseStatus::Paid)
        ->and($this->cash->balance())->toBe(-250000);
    Storage::disk('local')->assertExists($expense->attachment);
});

it('generar los recurrentes del mes y pagarlos', function () {
    RecurringExpense::query()->create([
        'expense_category_id' => ExpenseCategory::query()->where('name', 'Alquiler de cancha')->value('id'),
        'description' => 'Alquiler de cancha', 'amount' => 1200000, 'day_of_month' => 5, 'starts_on' => '2026-09-01',
    ]);

    Livewire::test(ManageRecurringExpenses::class)
        ->callAction('generate', data: ['period' => '2026-09-01'])
        ->assertNotified('Gastos pendientes generados: 1.');

    $pending = Expense::query()->sole();
    Livewire::test(ManageExpenses::class)
        ->callTableAction('pay', $pending, data: ['money_account_id' => $this->bank->id, 'paid_on' => '2026-09-06'])
        ->assertNotified('Gasto pagado.');

    expect($pending->fresh()->status)->toBe(ExpenseStatus::Paid)
        ->and($this->bank->balance())->toBe(-1200000);
});

it('nueva transferencia', function () {
    Livewire::test(ManageTransfers::class)
        ->callAction('transfer', data: ['from_account_id' => $this->cash->id, 'to_account_id' => $this->bank->id, 'amount' => 100000, 'transferred_on' => '2026-09-15'])
        ->assertHasNoActionErrors();

    Livewire::test(ManageTransfers::class)
        ->callAction('transfer', data: ['from_account_id' => $this->cash->id, 'to_account_id' => $this->cash->id, 'amount' => 1, 'transferred_on' => '2026-09-15'])
        ->assertHasActionErrors(['to_account_id']);

    expect($this->bank->balance())->toBe(100000)->and($this->cash->balance())->toBe(-100000);
});

it('la página de informes muestra el balance y cambia de pestaña', function () {
    Livewire::test(Reports::class)
        ->assertSee('Saldo inicial')
        ->set('tab', 'morosos')
        ->assertSee('No hay morosos.')
        ->set('tab', 'saldos')
        ->assertSee('Todas las familias están al día.');
});
