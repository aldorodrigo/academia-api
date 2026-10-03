<?php

use App\Enums\BillingUnit;
use App\Enums\DailyGrouping;
use App\Enums\FeeFrequency;
use App\Enums\MidPeriod;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Seasons\Pages\CreateSeason;
use App\Filament\Resources\Seasons\Support\SeasonPlan;
use App\Models\Organization;
use App\Models\Program;
use App\Support\Roles\RoleAssigner;
use App\Support\Tenancy\CurrentOrganization;
use Livewire\Livewire;

/** Plan anual 2027 con la frecuencia dada. */
function planState(array $overrides = []): array
{
    return array_merge([
        'name' => '2027', 'kind' => 'anual', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31',
        'program_ids' => [], 'fee_frequency' => 'mensual', 'daily_basis' => 'entrenamiento', 'daily_grouping' => 'mes',
        'due_days' => 9, 'issue_upfront' => false, 'mid_period' => 'completo', 'fee_amount' => 150000,
    ], $overrides);
}

describe('unidad de cada cuota', function () {
    it('sale de la frecuencia y, en por día, de la agrupación', function (string $frequency, ?string $grouping, BillingUnit $unit) {
        expect(BillingUnit::for($frequency, $grouping))->toBe($unit);
    })->with([
        ['mensual', null, BillingUnit::Month],
        ['quincenal', null, BillingUnit::Fortnight],
        ['semanal', null, BillingUnit::Week],
        ['diaria', 'mes', BillingUnit::Month],
        ['diaria', 'semana', BillingUnit::Week],
        ['diaria', 'dia', BillingUnit::Day],
    ]);

    it('sin plan de cobro no hay unidad', function () {
        expect(BillingUnit::for(null))->toBeNull();
    });

    it('concuerda en género', function (BillingUnit $unit, array $forms) {
        expect([
            $unit->each(), $unit->current(), $unit->next(), $unit->ofCurrent(),
            $unit->whole(), $unit->remainder(), $unit->midway(), $unit->within(),
        ])->toBe($forms);
    })->with([
        'mes' => [BillingUnit::Month, ['cada mes', 'este mes', 'el mes que viene', 'del mes en curso', 'el mes completo', 'lo que falta del mes', 'a mitad de mes', 'en el mes']],
        'quincena' => [BillingUnit::Fortnight, ['cada quincena', 'esta quincena', 'la quincena que viene', 'de la quincena en curso', 'la quincena completa', 'lo que falta de la quincena', 'a mitad de quincena', 'en la quincena']],
        'semana' => [BillingUnit::Week, ['cada semana', 'esta semana', 'la semana que viene', 'de la semana en curso', 'la semana completa', 'lo que falta de la semana', 'a mitad de semana', 'en la semana']],
    ]);

    it('el día no tiene "mitad de…"', function () {
        expect(BillingUnit::Day->allowsMidway())->toBeFalse()
            ->and(BillingUnit::Month->allowsMidway())->toBeTrue()
            ->and(BillingUnit::Day->createdAtStart())->toBe('el mismo día de cada entrenamiento')
            ->and(BillingUnit::Day->createdAfter())->toBe('unos días después de cada clase')
            ->and(BillingUnit::Fortnight->createdAtStart())->toBe('al empezar cada quincena');
    });

    it('dice el vencimiento como se piensa', function (BillingUnit $unit, int $days, string $text) {
        expect($unit->dueText($days))->toBe($text);
    })->with([
        [BillingUnit::Month, 9, 'el día 10 de cada mes'],
        [BillingUnit::Month, 0, 'el día 1 de cada mes'],
        [BillingUnit::Month, 27, 'el día 28 de cada mes'],
        [BillingUnit::Month, 40, '40 días después de empezar el mes'],
        [BillingUnit::Fortnight, 3, 'a los 3 días de empezar cada quincena (el 4 y el 19)'],
        [BillingUnit::Fortnight, 0, 'el día que empieza cada quincena (el 1 y el 16)'],
        [BillingUnit::Fortnight, 20, '20 días después de empezar la quincena'],
        [BillingUnit::Week, 3, 'el jueves de cada semana'],
        [BillingUnit::Week, 0, 'el lunes de cada semana'],
        [BillingUnit::Week, 9, '9 días después de empezar la semana'],
        [BillingUnit::Day, 0, 'el mismo día'],
        [BillingUnit::Day, 1, 'al día siguiente'],
        [BillingUnit::Day, 5, 'a los 5 días'],
    ]);

    it('ofrece el vencimiento según la unidad y conserva un valor viejo', function () {
        expect(BillingUnit::Month->dueOptions())->toHaveCount(28)
            ->and(BillingUnit::Month->dueOptions()[9])->toBe('Día 10')
            ->and(BillingUnit::Week->dueOptions())->toBe([0 => 'Lunes', 1 => 'Martes', 2 => 'Miércoles', 3 => 'Jueves', 4 => 'Viernes', 5 => 'Sábado', 6 => 'Domingo'])
            ->and(BillingUnit::Fortnight->dueOptions()[3])->toBe('A los 3 días (el 4 y el 19)')
            ->and(BillingUnit::Month->dueOptions(40)[40])->toBe('40 días después de empezar el mes');
    });

    it('"a mitad de…" con la unidad', function () {
        expect(MidPeriod::optionsFor(BillingUnit::Fortnight))->toBe([
            'completo' => 'La quincena completa',
            'proporcional' => 'Lo que falta de la quincena (proporcional)',
            'proximo' => 'Desde la quincena que viene',
        ])->and(MidPeriod::Next->labelFor(BillingUnit::Month))->toBe('Desde el mes que viene');
    });
});

describe('resumen y ejemplos del asistente', function () {
    beforeEach(function () {
        $this->club = Organization::factory()->create(['slug' => 'ritmo']);
        app(CurrentOrganization::class)->set($this->club);
    });

    it('nombra la unidad en el resumen', function (array $overrides, string $expected) {
        expect(SeasonPlan::summary(planState($overrides)))->toContain($expected)->not->toContain('período');
    })->with([
        'mensual' => [[], 'que vence el día 10 de cada mes. Cada cuota se crea al empezar cada mes.'],
        'quincenal' => [['fee_frequency' => 'quincenal', 'due_days' => 3], 'que vence a los 3 días de empezar cada quincena (el 4 y el 19). Cada cuota se crea al empezar cada quincena.'],
        'semanal' => [['fee_frequency' => 'semanal', 'due_days' => 3], 'que vence el jueves de cada semana. Cada cuota se crea al empezar cada semana.'],
        'por día, por día' => [['fee_frequency' => 'diaria', 'daily_grouping' => 'dia', 'due_days' => 0], 'que vence el mismo día. Cada cuota se crea el mismo día de cada entrenamiento.'],
        'por clase dictada, por mes' => [['fee_frequency' => 'diaria', 'daily_basis' => 'dictado', 'due_days' => 5], 'Cada cuota se crea al terminar cada mes, con las clases que se dieron'],
    ]);

    it('los ejemplos se llaman como la cuota que ve la familia', function () {
        $fortnight = SeasonPlan::examples(planState(['fee_frequency' => 'quincenal', 'due_days' => 3]))->pluck('period')->all();
        $week = SeasonPlan::examples(planState(['fee_frequency' => 'semanal', 'starts_on' => '2027-01-04']))->first();

        expect($fortnight)->toBe(['1.ª quincena ene 2027', '2.ª quincena ene 2027', '1.ª quincena feb 2027'])
            ->and($week['period'])->toBe('semana 4–10 ene')
            ->and(SeasonPlan::dueExample(planState()))->toBe('Por ejemplo, «Cuota enero 2027» vence el 10/01/2027.')
            // Empieza un viernes: la primera semana está recortada; el ejemplo usa la primera completa.
            ->and(SeasonPlan::dueExample(planState(['fee_frequency' => 'semanal', 'due_days' => 3])))
            ->toBe('Por ejemplo, «Cuota semana 4–10 ene» vence el 07/01/2027.');
    });

    it('preview manda las palabras ya armadas', function () {
        $admin = memberOf($this->club);
        app(RoleAssigner::class)->assign($this->club, $admin, OrganizationRole::Admin);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/setup/seasons/preview', planState(['fee_frequency' => 'semanal', 'due_days' => 3]), ['X-Organization' => 'ritmo'])
            ->assertOk()
            ->assertJsonPath('data.terms.unit', 'semana')
            ->assertJsonPath('data.terms.issue_now', 'Al empezar cada semana')
            ->assertJsonPath('data.terms.issue_now_help', 'La familia ve solo la cuota de la semana en curso.')
            ->assertJsonPath('data.terms.midway', 'Si alguien se inscribe a mitad de semana, se cobra')
            ->assertJsonPath('data.terms.mid_period_options.0', ['value' => 'completo', 'label' => 'La semana completa'])
            ->assertJsonPath('data.terms.due_question', '¿Qué día de la semana vence?')
            ->assertJsonPath('data.terms.due_options.3', ['value' => 3, 'label' => 'Jueves'])
            ->assertJsonPath('data.terms.due_text', 'el jueves de cada semana');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/setup/seasons/preview', planState(['fee_frequency' => 'diaria', 'daily_grouping' => 'dia']), ['X-Organization' => 'ritmo'])
            ->assertJsonPath('data.terms.midway', null)
            ->assertJsonPath('data.terms.issue_now', 'El mismo día de cada entrenamiento');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/setup/seasons/preview', planState(['fee_frequency' => null]), ['X-Organization' => 'ritmo'])
            ->assertJsonPath('data.terms', null);
    });

    it('el asistente pregunta el vencimiento según la unidad y oculta "a mitad de…" por día', function () {
        $admin = memberOf($this->club);
        app(RoleAssigner::class)->assign($this->club, $admin, OrganizationRole::Admin);
        $this->actingAs($admin);
        filament()->setTenant($this->club);
        Program::factory()->for($this->club)->create();

        Livewire::test(CreateSeason::class)
            ->fillForm(['fee_frequency' => FeeFrequency::Weekly->value])
            ->assertSee('¿Qué día de la semana vence?')
            ->assertSee('Al empezar cada semana (recomendado)')
            ->assertSee('Si alguien se inscribe a mitad de semana, se cobra')
            ->fillForm(['fee_frequency' => FeeFrequency::Daily->value, 'daily_grouping' => DailyGrouping::Day->value])
            ->assertSee('El mismo día de cada entrenamiento (recomendado)')
            ->assertDontSee('a mitad de');
    });
});
